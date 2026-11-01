<?php

declare(strict_types=1);

namespace Bladestan\Tests\Compiler;

use App\Models\User;
use Bladestan\Compiler\SignatureMerger;
use Bladestan\ValueObject\TemplateSignature;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionMethod;

/**
 * Covers the covariance rules a child `@extends` layout must obey: it may
 * narrow a parent's type but never widen it, and an incompatible type is an
 * error. The rules are exercised through {@see SignatureMerger::mergePair()}
 * with hand-built signatures so no `@extends` fixture chain is needed.
 */
final class SignatureMergerTest extends PHPStanTestCase
{
    private SignatureMerger $signatureMerger;

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../config/extension.neon'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->signatureMerger = self::getContainer()->getByType(SignatureMerger::class);
    }

    public function testIdenticalTypesMergeWithoutError(): void
    {
        [$merged, $errors] = $this->mergePair([
            'title' => 'string',
        ], [
            'title' => 'string',
        ]);

        self::assertSame('string', $merged->getVariableType('title'));
        self::assertSame([], $errors);
    }

    public function testChildMayNarrowAParentType(): void
    {
        // Parent allows ?string; child narrows to string. Allowed (covariant),
        // and the merged contract keeps the child's narrower type.
        [$merged, $errors] = $this->mergePair([
            'title' => 'string',
        ], [
            'title' => '?string',
        ]);

        self::assertSame('string', $merged->getVariableType('title'));
        self::assertSame([], $errors);
    }

    public function testChildMayNotWidenAParentType(): void
    {
        // Parent requires string; child widens to string|int. Forbidden. The
        // parent's narrower type is kept so the call site must still satisfy it.
        [$merged, $errors] = $this->mergePair([
            'title' => 'string|int',
        ], [
            'title' => 'string',
        ]);

        self::assertSame('string', $merged->getVariableType('title'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('may narrow types but not widen them', $errors[0]);
    }

    public function testIncompatibleTypesAreReported(): void
    {
        // Neither type is a subtype of the other.
        [$merged, $errors] = $this->mergePair([
            'title' => 'int',
        ], [
            'title' => 'string',
        ]);

        self::assertSame('int', $merged->getVariableType('title'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('incompatible', $errors[0]);
    }

    public function testParentOnlyVariablesSurviveTheMerge(): void
    {
        [$merged] = $this->mergePair([
            'title' => 'string',
        ], [
            'user' => User::class,
        ]);

        self::assertSame('string', $merged->getVariableType('title'));
        self::assertSame(User::class, $merged->getVariableType('user'));
    }

    /**
     * @param array<string, string> $childVariables
     * @param array<string, string> $parentVariables
     * @return array{TemplateSignature, list<string>}
     */
    private function mergePair(array $childVariables, array $parentVariables): array
    {
        $reflectionMethod = new ReflectionMethod($this->signatureMerger, 'mergePair');

        /** @var array{TemplateSignature, list<string>} $result */
        $result = $reflectionMethod->invokeArgs($this->signatureMerger, [
            new TemplateSignature($childVariables, true),
            new TemplateSignature($parentVariables, true),
            '/views/child.blade.php',
            '/views/parent.blade.php',
        ]);

        return $result;
    }
}
