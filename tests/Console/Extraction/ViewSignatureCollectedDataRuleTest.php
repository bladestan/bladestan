<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console\Extraction;

use App\Models\User;
use Bladestan\Console\Extraction\TemplateFreeVariableCollector;
use Bladestan\Console\Extraction\ViewDataCollector;
use Bladestan\Console\Extraction\ViewSignatureCollectedDataRule;
use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ViewSignatureCollectedDataRule>
 */
final class ViewSignatureCollectedDataRuleTest extends RuleTestCase
{
    public function testHarvestsAndMergesRenderSites(): void
    {
        $signatures = $this->harvestSignatures(__DIR__ . '/Fixture/render-sites.php');

        $this->assertArrayHasKey('signed-template', $signatures);

        $signature = $signatures['signed-template'];
        self::assertStringEndsWith('resources/views/signed-template.blade.php', $signature['template']);
        // $title is passed as string and int by the two sites; $user by only
        // one, so it is made nullable.
        self::assertSame(
            [
                'title' => 'string|int',
                'user' => 'App\Models\User|null',
            ],
            $signature['variables'],
        );

        // A generated signature is only useful if Bladestan then accepts it, so
        // every harvested type must resolve through the same resolver the
        // call-site rule uses.
        $typeStringResolver = self::getContainer()->getByType(TypeStringResolver::class);
        foreach ($signature['variables'] as $type) {
            $typeStringResolver->resolve($type);
        }

        $this->addToAssertionCount(1);
    }

    public function testMixedNeverWidensAConcreteType(): void
    {
        $signatures = $this->harvestSignatures(__DIR__ . '/Fixture/render-sites.php');

        // One site of 'bar' passes a User, the other a mixed value. The union
        // must keep the concrete type rather than emit App\Models\User|mixed,
        // which PHPStan would collapse to a bare mixed that checks nothing.
        $this->assertArrayHasKey('bar', $signatures);
        self::assertSame(
            [
                'thing' => User::class,
            ],
            $signatures['bar']['variables'],
        );
    }

    public function testTypesScopeForwardedIncludePartial(): void
    {
        // A bare @include compiles to a scope-forwarding view() call in the
        // includer and no explicit data. The partial declares nothing itself; it
        // reads $greeting from the forwarded scope. Its signature must therefore
        // be recovered from the includer's scope, keyed to the partial by the
        // @bladestan-source header the compiler writes.
        $partialPath = self::getContainer()
            ->getByType(TemplateFilePathResolver::class)
            ->resolveExistingFilePath('foo');

        $directory = sys_get_temp_dir() . '/bladestan-include-' . getmypid();
        @mkdir($directory, 0o777, true);

        $includer = $directory . '/compiled-includer.php';
        file_put_contents($includer, "<?php\n\$greeting = 'hello';\nview('foo', [], get_defined_vars());\n");

        $partial = $directory . '/compiled-partial.php';
        file_put_contents($partial, "<?php\n// @bladestan-source: {$partialPath}\necho \$greeting;\n");

        try {
            $signatures = $this->harvestSignatures($includer, $partial);
        } finally {
            @unlink($includer);
            @unlink($partial);
            @rmdir($directory);
        }

        $this->assertArrayHasKey('foo', $signatures);
        self::assertSame([
            'greeting' => 'string',
        ], $signatures['foo']['variables']);
    }

    /**
     * @return array<string, array{template: string, view: string, variables: array<string, string>}>
     */
    private function harvestSignatures(string ...$files): array
    {
        $signatures = [];
        foreach ($this->gatherAnalyserErrors($files) as $error) {
            $prefix = ViewSignatureCollectedDataRule::SENTINEL . ' ';
            if (! str_starts_with($error->getMessage(), $prefix)) {
                continue;
            }

            /** @var array{template: string, view: string, variables: array<string, string>} $payload */
            $payload = json_decode(substr($error->getMessage(), strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
            $signatures[$payload['view']] = $payload;
        }

        return $signatures;
    }

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ViewSignatureCollectedDataRule::class);
    }

    /**
     * @return array<Collector<Node, mixed>>
     */
    protected function getCollectors(): array
    {
        return [
            self::getContainer()->getByType(ViewDataCollector::class),
            self::getContainer()->getByType(TemplateFreeVariableCollector::class),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../../Rules/config/configured_extension.neon',
            __DIR__ . '/../../../config/generate-signatures.neon',
        ];
    }
}
