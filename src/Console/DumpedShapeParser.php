<?php

declare(strict_types=1);

namespace Bladestan\Console;

/**
 * Parses the `array{...}` shapes PHPStan's `dumpType` emits for a view's data
 * argument and merges the shapes collected across every render site of one
 * template into a single variable => type map.
 *
 * A template rendered from several call sites takes the union of what each site
 * passes; a variable some site omits is made nullable, mirroring the runtime
 * fact that it may be absent.
 *
 * @see \Bladestan\Tests\Console\DumpedShapeParserTest
 */
final class DumpedShapeParser
{
    public function __construct(
        private readonly DumpedTypeNormalizer $dumpedTypeNormalizer,
    ) {
    }

    /**
     * Parse one dumped `array{...}` shape into its members.
     *
     * @return array<string, array{type: string, optional: bool}>|null
     *         Null when the dump is not an array shape (nothing to harvest).
     */
    public function parseShape(string $type): ?array
    {
        $type = trim($type);
        if (! str_starts_with($type, 'array{') || ! str_ends_with($type, '}')) {
            return null;
        }

        $inner = substr($type, 6, -1);
        $members = [];
        foreach ($this->splitTopLevel($inner, ',') as $part) {
            if (trim($part) === '') {
                continue;
            }

            $keyAndValue = $this->splitTopLevel($part, ':', 2);
            if (count($keyAndValue) < 2) {
                return null;
            }

            $key = trim(trim($keyAndValue[0]), '\'"');
            $optional = str_ends_with($key, '?');
            $key = rtrim($key, '?');
            $members[$key] = [
                'type' => trim($keyAndValue[1]),
                'optional' => $optional,
            ];
        }

        return $members;
    }

    /**
     * Merge the shapes harvested for one view into an ordered variable => type
     * map, normalizing each member type and adding `null` to any variable that
     * is optional or absent from some site.
     *
     * @param list<array<string, array{type: string, optional: bool}>> $shapes
     * @return array<string, string>
     */
    public function mergeShapes(array $shapes): array
    {
        $siteCount = count($shapes);

        /** @var array<string, array<string, true>> $typesByName */
        $typesByName = [];
        /** @var array<string, int> $presenceByName */
        $presenceByName = [];

        foreach ($shapes as $shape) {
            foreach ($shape as $name => $member) {
                $typesByName[$name] ??= [];
                $presenceByName[$name] ??= 0;

                $typesByName[$name][$this->dumpedTypeNormalizer->normalize($member['type'])] = true;
                $presenceByName[$name]++;

                if ($member['optional']) {
                    $typesByName[$name]['null'] = true;
                }
            }
        }

        $merged = [];
        foreach ($typesByName as $name => $typeSet) {
            $parts = array_keys($typeSet);
            if ($presenceByName[$name] < $siteCount) {
                $parts[] = 'null';
            }

            $parts = array_values(array_unique($parts));
            // Keep null last for readability (?T reads better than null|T).
            usort($parts, fn (string $a, string $b): int => ($a === 'null' ? 1 : 0) <=> ($b === 'null' ? 1 : 0));

            $merged[$name] = implode('|', $parts);
        }

        return $merged;
    }

    /**
     * Split a type string on $separator at the top nesting level only, so
     * separators inside `(...)`, `[...]`, `{...}` and `<...>` are left intact.
     *
     * @return list<string>
     */
    public function splitTopLevel(string $subject, string $separator, int $limit = PHP_INT_MAX): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($subject);

        for ($i = 0; $i < $length; $i++) {
            $character = $subject[$i];
            if (str_contains('([{<', $character)) {
                $depth++;
            } elseif (str_contains(')]}>', $character)) {
                $depth--;
            }

            if ($character === $separator && $depth === 0 && count($parts) < $limit - 1) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }
}
