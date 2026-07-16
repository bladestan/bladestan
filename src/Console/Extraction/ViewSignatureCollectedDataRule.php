<?php

declare(strict_types=1);

namespace Bladestan\Console\Extraction;

use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use InvalidArgumentException;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function array_column;
use function array_key_exists;
use function array_keys;
use function count;
use function explode;
use function implode;
use function in_array;
use function json_encode;
use function usort;
use const JSON_UNESCAPED_SLASHES;

/**
 * Turns the render-site data gathered by {@see ViewDataCollector} into one
 * signature per view, transported to `bladestan:generate-signatures` as a JSON
 * payload on a rule error.
 *
 * PHPStan has no first-class channel for handing structured data back to a
 * command, so this reuses its error pipeline as the transport: each view
 * becomes a single error whose message is a sentinel plus the JSON signature,
 * and the command reads those messages from `--error-format=json` output. The
 * "error" is never shown to a user; it only carries data.
 *
 * A view rendered from several sites takes the union of what each site passes,
 * and a variable some site omits is made nullable, mirroring the runtime fact
 * that it may be absent. The view name is resolved to its template file here,
 * through the same resolver the validator uses, so a view that resolves only at
 * runtime is skipped rather than written to the wrong file.
 *
 * Partials reached through a bare `@include` pass no explicit data; their inputs
 * are the variables they read but never define ({@see TemplateFreeVariableCollector}),
 * typed from what the including templates forward. A partial is therefore signed
 * only once its includers are themselves signed, since the forwarded types are
 * otherwise unknown.
 *
 * @implements Rule<CollectedDataNode>
 * @see \Bladestan\Tests\Console\Extraction\ViewSignatureCollectedDataRuleTest
 */
final class ViewSignatureCollectedDataRule implements Rule
{
    /**
     * Message prefix that marks an error as generator data rather than a real
     * diagnostic. Kept in sync with the reader in
     * {@see \Bladestan\Console\GenerateBladeSignaturesCommand}.
     *
     * @var string
     */
    public const SENTINEL = '@bladestan-signature-data';

    public function __construct(
        private readonly TemplateFilePathResolver $templateFilePathResolver,
    ) {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @param CollectedDataNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $freeVariablesBySource = $this->freeVariablesBySource($node);

        /** @var array<string, list<array{view: string, variables: array<string, string>, forwarded: array<string, string>, line: int}>> $sitesByView */
        $sitesByView = [];
        /** @var array<string, array{file: string, line: int}> $locationByView */
        $locationByView = [];

        foreach ($node->get(ViewDataCollector::class) as $file => $collectedPerNode) {
            foreach ($collectedPerNode as $sites) {
                foreach ($sites as $site) {
                    $view = $site['view'];
                    $sitesByView[$view][] = $site;
                    $locationByView[$view] ??= [
                        'file' => $file,
                        'line' => $site['line'],
                    ];
                }
            }
        }

        $errors = [];
        foreach ($sitesByView as $view => $sites) {
            try {
                $templatePath = $this->templateFilePathResolver->resolveExistingFilePath($view);
            } catch (InvalidArgumentException) {
                // A view that does not resolve to a file on disk (a dynamic
                // name, a package view we cannot map) has nowhere to write a
                // signature, so it is skipped rather than aborting the run.
                continue;
            }

            $payload = [
                'template' => $templatePath,
                'view' => $view,
                'variables' => $this->mergeSites($sites, $freeVariablesBySource[$templatePath] ?? []),
            ];

            $errors[] = RuleErrorBuilder::message(self::SENTINEL . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES))
                ->file($locationByView[$view]['file'])
                ->line($locationByView[$view]['line'])
                ->identifier('bladestan.generateSignature')
                ->build();
        }

        return $errors;
    }

    /**
     * The variables each source template reads without defining, i.e. the inputs
     * a partial expects to receive from its includers.
     *
     * @param CollectedDataNode $node
     * @return array<string, list<string>> source template path => variable names
     */
    private function freeVariablesBySource(Node $node): array
    {
        /** @var array<string, array<string, true>> $namesBySource */
        $namesBySource = [];

        foreach ($node->get(TemplateFreeVariableCollector::class) as $collectedPerNode) {
            foreach ($collectedPerNode as $usage) {
                $namesBySource[$usage['source']][$usage['name']] = true;
            }
        }

        $freeBySource = [];
        foreach ($namesBySource as $source => $names) {
            $freeBySource[$source] = array_keys($names);
        }

        return $freeBySource;
    }

    /**
     * Build the variable => type map for one view.
     *
     * Explicit data passed to the view (from `view()` calls and `@include`s with
     * data) is always included. When the view is reached by a bare `@include`
     * that forwards its includer's scope, each variable the partial reads but
     * never defines is added too, typed from what those includers forward. A
     * variable that no includer forwards falls back to `mixed`, still declaring
     * the input so it is no longer reported as undefined.
     *
     * @param list<array{view: string, variables: array<string, string>, forwarded: array<string, string>, line: int}> $sites
     * @param list<string> $freeVariableNames
     * @return array<string, string>
     */
    private function mergeSites(array $sites, array $freeVariableNames): array
    {
        $variables = $this->mergeTypeMaps(array_column($sites, 'variables'), count($sites));

        $forwardingMaps = [];
        foreach ($sites as $site) {
            if ($site['forwarded'] !== []) {
                $forwardingMaps[] = $site['forwarded'];
            }
        }

        if ($forwardingMaps !== []) {
            $forwarded = $this->mergeTypeMaps($forwardingMaps, count($forwardingMaps));
            foreach ($freeVariableNames as $freeVariableName) {
                if (array_key_exists($freeVariableName, $variables)) {
                    continue;
                }

                $variables[$freeVariableName] = $forwarded[$freeVariableName] ?? 'mixed';
            }
        }

        return $variables;
    }

    /**
     * Union the per-site type maps into one variable => type map. The type of a
     * variable is the union of the types each map gives it; a variable absent
     * from some of the `$siteCount` maps gains `null`, since it may be absent at
     * runtime. Types are unioned at the string level (already fully-qualified,
     * parser-safe descriptions), with `null` kept last so the result reads as
     * `T|null`.
     *
     * @param list<array<string, string>> $maps
     * @return array<string, string>
     */
    private function mergeTypeMaps(array $maps, int $siteCount): array
    {
        /** @var array<string, list<string>> $typesByName */
        $typesByName = [];
        /** @var array<string, int> $presenceByName */
        $presenceByName = [];

        foreach ($maps as $map) {
            foreach ($map as $name => $type) {
                $typesByName[$name] ??= [];
                $presenceByName[$name] ??= 0;
                $presenceByName[$name]++;

                foreach (explode('|', $type) as $part) {
                    if (! in_array($part, $typesByName[$name], true)) {
                        $typesByName[$name][] = $part;
                    }
                }
            }
        }

        $merged = [];
        foreach ($typesByName as $name => $parts) {
            if ($presenceByName[$name] < $siteCount && ! in_array('null', $parts, true)) {
                $parts[] = 'null';
            }

            // Keep null last for readability (T|null reads better than null|T).
            usort($parts, fn (string $a, string $b): int => ($a === 'null' ? 1 : 0) <=> ($b === 'null' ? 1 : 0));

            $merged[$name] = implode('|', $parts);
        }

        return $merged;
    }
}
