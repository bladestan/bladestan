<?php

declare(strict_types=1);

namespace Bladestan\Bootstrap;

use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function preg_quote;
use function str_starts_with;

/**
 * Works out which PHPStan command a process is running, from its argv.
 *
 * The bootstrap runs in every PHPStan process and must know the command before
 * PHPStan has parsed its own input: only `analyse` reads the compiled
 * templates, so only `analyse` should build them, and a parallel worker must
 * never compile at all. The command cannot be read from a fixed argv position.
 * PHPStan's binary calls `setDefaultCommand('analyse')`, so the command name is
 * frequently absent (`phpstan --configuration=phpstan.neon` analyses without
 * naming the command), and any global option pushes it past argv[1]
 * (`phpstan -v analyse`). Reading argv[1] alone left both of those looking like
 * some other command, which silently skipped compilation.
 *
 * The resolution mirrors Symfony Console, which is what actually decides the
 * command PHPStan runs: the name is the first token that does not start with
 * `-`, and an absent name means the default command. No token is ever skipped
 * as an option's value, because none of PHPStan's application-level options
 * take one; Symfony reads `phpstan -c phpstan.neon` as command "phpstan.neon"
 * too, and PHPStan rejects it as undefined.
 *
 * @see \Bladestan\Tests\Bootstrap\PhpStanCommandResolverTest
 */
final class PhpStanCommandResolver
{
    public const ANALYSE = 'analyse';

    public const WORKER = 'worker';

    public const FIXER_WORKER = 'fixer:worker';

    /**
     * Every name PHPStan's binary answers to, including the commands Symfony
     * registers itself. Symfony resolves an unambiguous abbreviation to the full
     * name (`phpstan an` runs `analyse`), so the raw token has to be matched
     * against the whole list rather than compared to a literal: a token that
     * matches two of these names is one PHPStan rejects as ambiguous.
     *
     * @var list<string>
     */
    private const COMMANDS = [
        self::ANALYSE,
        'analyze',
        self::WORKER,
        self::FIXER_WORKER,
        'clear-result-cache',
        'dump-parameters',
        'diagnose',
        'help',
        'list',
        'completion',
    ];

    /**
     * Names that reach a command registered under a different one.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'analyze' => self::ANALYSE,
    ];

    /**
     * The canonical name of the command this argv runs.
     *
     * A token that names no command, or names several, is returned unchanged:
     * PHPStan will reject that invocation anyway, and reporting it as an unknown
     * command keeps the caller from compiling for a run that never happens.
     *
     * @param list<string> $argv
     */
    public function resolve(array $argv): string
    {
        $name = $this->commandName($argv);

        // No name at all, which setDefaultCommand('analyse') turns into an
        // analyse run. An empty token counts as absent here for the same reason
        // it does in Symfony, where it fails the truthiness check that selects
        // the default command.
        if ($name === '') {
            return self::ANALYSE;
        }

        return $this->canonicalName($name);
    }

    /**
     * @param list<string> $argv
     */
    public function isAnalyse(array $argv): bool
    {
        return $this->resolve($argv) === self::ANALYSE;
    }

    /**
     * Whether this process is one of the child processes PHPStan spawns to
     * analyse in parallel. They load the bootstrap like any other process but
     * must never compile: their siblings are already reading the compiled files.
     *
     * @param list<string> $argv
     */
    public function isWorker(array $argv): bool
    {
        $command = $this->resolve($argv);

        return $command === self::WORKER || $command === self::FIXER_WORKER;
    }

    /**
     * The first token Symfony Console would read as the command name, or an
     * empty string when there is none.
     *
     * @param list<string> $argv
     */
    private function commandName(array $argv): string
    {
        foreach (array_slice($argv, 1) as $token) {
            if (str_starts_with($token, '-')) {
                continue;
            }

            return $token;
        }

        return '';
    }

    private function canonicalName(string $name): string
    {
        // Symfony matches an abbreviation per namespace segment, so `f:w` finds
        // `fixer:worker` and `clear` finds `clear-result-cache`. Build the same
        // expression it does and keep the canonical name of every command it hits.
        $expression = '{^' . implode('[^:]*:', array_map(
            static fn (string $segment): string => preg_quote($segment, '{}'),
            explode(':', $name),
        )) . '[^:]*}';

        /** @var list<string> $matched */
        $matched = [];
        foreach (self::COMMANDS as $spelling) {
            if (preg_match($expression, $spelling) !== 1) {
                continue;
            }

            $canonical = self::ALIASES[$spelling] ?? $spelling;
            if (! in_array($canonical, $matched, true)) {
                $matched[] = $canonical;
            }
        }

        // Exactly one command matched, so that is the one PHPStan runs. Anything
        // else is a name PHPStan cannot resolve either.
        return count($matched) === 1 ? $matched[0] : $name;
    }
}
