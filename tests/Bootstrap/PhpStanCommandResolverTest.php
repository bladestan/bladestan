<?php

declare(strict_types=1);

namespace Bladestan\Tests\Bootstrap;

use Bladestan\Bootstrap\PhpStanCommandResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpStanCommandResolverTest extends TestCase
{
    private PhpStanCommandResolver $phpStanCommandResolver;

    protected function setUp(): void
    {
        $this->phpStanCommandResolver = new PhpStanCommandResolver();
    }

    public function testNamedAnalyseCommand(): void
    {
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(
            ['vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon'],
        ));
    }

    public function testAbsentCommandIsTheDefaultAnalyseCommand(): void
    {
        // PHPStan's binary calls setDefaultCommand('analyse'), so this analyses
        // without naming the command. Reading argv[1] saw "--configuration=…"
        // and skipped compilation, leaving PHPStan to reject its own
        // configured `.bladestan` path as non-existent.
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(
            ['vendor/bin/phpstan', '--configuration=phpstan.neon', '--no-progress'],
        ));
    }

    public function testNoArgumentsAtAllIsTheDefaultAnalyseCommand(): void
    {
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(['vendor/bin/phpstan']));
    }

    public function testGlobalOptionBeforeTheCommand(): void
    {
        // Any option pushes the command past argv[1]; `-v analyse` used to read
        // as some other command and silently skip compilation.
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(
            ['vendor/bin/phpstan', '-v', 'analyse', '-c', 'phpstan.neon'],
        ));
    }

    public function testAnalyzeSpellingResolvesToAnalyse(): void
    {
        $argv = ['vendor/bin/phpstan', 'analyze'];

        $this->assertSame(PhpStanCommandResolver::ANALYSE, $this->phpStanCommandResolver->resolve($argv));
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse($argv));
    }

    public function testUnambiguousAbbreviationResolvesToTheFullCommand(): void
    {
        // Symfony resolves an abbreviation to the command it uniquely matches,
        // and so does PHPStan. "analys" matches both spellings of one command.
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(['vendor/bin/phpstan', 'an']));
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(['vendor/bin/phpstan', 'analys']));
    }

    public function testAmbiguousAbbreviationIsNotAnalyse(): void
    {
        // "d" matches both diagnose and dump-parameters, which PHPStan rejects.
        $this->assertFalse($this->phpStanCommandResolver->isAnalyse(['vendor/bin/phpstan', 'd']));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonAnalyseCommandProvider(): iterable
    {
        yield 'clear result cache' => [['vendor/bin/phpstan', 'clear-result-cache', '-c', 'phpstan.neon']];
        yield 'diagnose' => [['vendor/bin/phpstan', 'diagnose']];
        yield 'dump parameters' => [['vendor/bin/phpstan', 'dump-parameters', '--json']];
        yield 'help' => [['vendor/bin/phpstan', 'help', 'analyse']];
        yield 'worker' => [['vendor/bin/phpstan', 'worker', '--port', '1234']];
        yield 'abbreviated clear result cache' => [['vendor/bin/phpstan', 'clear']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('nonAnalyseCommandProvider')]
    public function testOtherCommandsDoNotAnalyse(array $argv): void
    {
        $this->assertFalse($this->phpStanCommandResolver->isAnalyse($argv));
    }

    public function testWorkerCommandsAreRecognised(): void
    {
        $this->assertTrue($this->phpStanCommandResolver->isWorker(
            ['vendor/bin/phpstan', 'worker', '--port', '1234', '--identifier', 'abc'],
        ));
        $this->assertTrue($this->phpStanCommandResolver->isWorker(
            ['vendor/bin/phpstan', 'fixer:worker', '--server-port', '1234'],
        ));
    }

    public function testAnalyseIsNotAWorker(): void
    {
        $this->assertFalse($this->phpStanCommandResolver->isWorker(['vendor/bin/phpstan', 'analyse']));
        $this->assertFalse($this->phpStanCommandResolver->isWorker(['vendor/bin/phpstan', '--configuration=x.neon']));
    }

    public function testOptionValueWrittenWithASpaceIsReadAsTheCommandName(): void
    {
        // Symfony reads the first non-option token as the command name, so
        // `phpstan -c phpstan.neon` (no command) makes PHPStan itself fail with
        // "Command phpstan.neon is not defined". Compiling for a run that never
        // happens would be worse than not compiling, so this is not analyse.
        $this->assertFalse($this->phpStanCommandResolver->isAnalyse(
            ['vendor/bin/phpstan', '-c', 'phpstan.neon'],
        ));
    }

    public function testPathsAfterTheCommandDoNotChangeIt(): void
    {
        $this->assertTrue($this->phpStanCommandResolver->isAnalyse(
            ['vendor/bin/phpstan', 'analyse', '-c', 'phpstan.neon', 'app/Http', 'artisan'],
        ));
    }
}
