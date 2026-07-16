<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console\Extraction;

use Bladestan\Console\Extraction\ViewDataCollector;
use Bladestan\Console\Extraction\ViewSignatureCollectedDataRule;
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

    /**
     * @return array<string, array{template: string, view: string, variables: array<string, string>}>
     */
    private function harvestSignatures(string $file): array
    {
        $signatures = [];
        foreach ($this->gatherAnalyserErrors([$file]) as $error) {
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
        return [self::getContainer()->getByType(ViewDataCollector::class)];
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
