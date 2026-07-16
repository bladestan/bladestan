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
        /** @var array<string, list<array{view: string, variables: array<string, string>, line: int}>> $sitesByView */
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
                'variables' => $this->mergeSites($sites),
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
     * Merge every render site of one view into an ordered variable => type map.
     *
     * The type of a variable is the union of the types each site passes; a
     * variable absent from some site gains `null`, since at runtime it may not
     * be provided. Types are unioned at the string level (they are already
     * fully-qualified, parser-safe descriptions), with `null` kept last so the
     * result reads as `T|null`.
     *
     * @param list<array{view: string, variables: array<string, string>, line: int}> $sites
     * @return array<string, string>
     */
    private function mergeSites(array $sites): array
    {
        $siteCount = count($sites);

        /** @var array<string, list<string>> $typesByName */
        $typesByName = [];
        /** @var array<string, int> $presenceByName */
        $presenceByName = [];

        foreach ($sites as $site) {
            foreach ($site['variables'] as $name => $type) {
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
