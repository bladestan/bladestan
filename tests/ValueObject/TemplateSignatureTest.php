<?php

declare(strict_types=1);

namespace Bladestan\Tests\ValueObject;

use App\Models\User;
use Bladestan\ValueObject\TemplateSignature;
use PHPUnit\Framework\TestCase;

final class TemplateSignatureTest extends TestCase
{
    public function testEmptySignature(): void
    {
        $templateSignature = new TemplateSignature([]);

        $this->assertTrue($templateSignature->isEmpty());
        $this->assertSame([], $templateSignature->variables);
        $this->assertSame([], $templateSignature->getVariableNames());
        $this->assertFalse($templateSignature->isExplicit);
    }

    public function testSignatureWithVariables(): void
    {
        $variables = [
            'name' => 'string',
            'user' => User::class,
        ];
        $templateSignature = new TemplateSignature($variables);

        $this->assertFalse($templateSignature->isEmpty());
        $this->assertSame($variables, $templateSignature->variables);
        $this->assertSame(['name', 'user'], $templateSignature->getVariableNames());
    }

    public function testExplicitFlag(): void
    {
        $implicit = new TemplateSignature([
            'name' => 'string',
        ]);
        $explicit = new TemplateSignature([
            'name' => 'string',
        ], isExplicit: true);

        $this->assertFalse($implicit->isExplicit);
        $this->assertTrue($explicit->isExplicit);
    }

    public function testHasVariable(): void
    {
        $templateSignature = new TemplateSignature([
            'name' => 'string',
            'age' => 'int',
        ]);

        $this->assertTrue($templateSignature->hasVariable('name'));
        $this->assertTrue($templateSignature->hasVariable('age'));
        $this->assertFalse($templateSignature->hasVariable('missing'));
    }

    public function testGetVariableType(): void
    {
        $templateSignature = new TemplateSignature([
            'name' => 'string',
            'user' => User::class,
        ]);

        $this->assertSame('string', $templateSignature->getVariableType('name'));
        $this->assertSame(User::class, $templateSignature->getVariableType('user'));
        $this->assertNull($templateSignature->getVariableType('missing'));
    }

    public function testWithAdditionalVariablesDoesNotOverwriteExisting(): void
    {
        $templateSignature = new TemplateSignature([
            'name' => 'string',
        ], isExplicit: true);

        $merged = $templateSignature->withAdditionalVariables([
            'name' => 'int',
            'age' => 'int',
        ]);

        // 'name' keeps the original type, 'age' is added
        $this->assertSame('string', $merged->getVariableType('name'));
        $this->assertSame('int', $merged->getVariableType('age'));
        $this->assertTrue($merged->isExplicit);
    }

    public function testMergedWithOverridesOnConflict(): void
    {
        $base = new TemplateSignature([
            'name' => 'string',
            'age' => 'int',
        ]);
        $other = new TemplateSignature([
            'name' => 'mixed',
            'email' => 'string',
        ], isExplicit: true);

        $merged = $base->mergedWith($other);

        // 'name' is overridden by $other
        $this->assertSame('mixed', $merged->getVariableType('name'));
        $this->assertSame('int', $merged->getVariableType('age'));
        $this->assertSame('string', $merged->getVariableType('email'));
        // isExplicit is true because at least one source is explicit
        $this->assertTrue($merged->isExplicit);
    }

    public function testMergedWithPreservesExplicitFromEitherSide(): void
    {
        $explicit = new TemplateSignature([], isExplicit: true);
        $implicit = new TemplateSignature([]);

        $this->assertTrue($explicit->mergedWith($implicit)->isExplicit);
        $this->assertTrue($implicit->mergedWith($explicit)->isExplicit);
        $this->assertFalse($implicit->mergedWith($implicit)->isExplicit);
    }

    public function testImmutability(): void
    {
        $templateSignature = new TemplateSignature([
            'name' => 'string',
        ]);

        $withAdditional = $templateSignature->withAdditionalVariables([
            'age' => 'int',
        ]);
        $merged = $templateSignature->mergedWith(new TemplateSignature([
            'email' => 'string',
        ]));

        // Original is unchanged
        $this->assertSame([
            'name' => 'string',
        ], $templateSignature->variables);
        $this->assertSame(['name', 'age'], $withAdditional->getVariableNames());
        $this->assertSame(['name', 'email'], $merged->getVariableNames());
    }
}
