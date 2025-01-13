<?php

declare(strict_types=1);

namespace Bladestan\Compiler;

final class PhpContentExtractor
{
    /**
     * @see https://regex101.com/r/EnY0cr/1
     * @var string
     */
    private const IGNORING_FINAL_SEMICOLON = '/<\?php(.*?)([;:]?)((?:\s|(?:\/\*(?:[^\/]|(?<!\*)\/)+\/))*)\?>/s';

    /**
     * @see https://regex101.com/r/qqPf2x/1
     * @var string
     */
    private const PHP_OPEN_CLOSE_TAGS_REGEX = '#^\s*(/\*\* file: [^*]+, line: \d+ \*/)?.*?<\?php(.*?)\?>.*?$#ms';

    /**
     * @param string $bladeCompiledContent This should be the string that is pre-compiled for Blade and then compiled by Blade.
     */
    public function extract(string $bladeCompiledContent, bool $addPHPOpeningTag = true): string
    {
        // Terminate unterminated PHP snippets
        preg_match_all(self::IGNORING_FINAL_SEMICOLON, $bladeCompiledContent, $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            if ($block[2] !== ':' && $block[2] !== ';') {
                $bladeCompiledContent = str_replace($block[0], "<?php{$block[1]};{$block[3]}?>", $bladeCompiledContent);
            }
        }

        // Merge multiple code blocks appearing on a single line
        $bladeCompiledContent = preg_replace('#\s*\?>.*?<\?php\s*#', ' ', $bladeCompiledContent) ?? '';

        preg_match_all(self::PHP_OPEN_CLOSE_TAGS_REGEX, $bladeCompiledContent, $matches, PREG_SET_ORDER);

        $phpContents = [];
        foreach ($matches as $match) {
            $comment = $match[1];
            if ($comment !== '') {
                // Find last comment before code
                preg_match('#.*(\/\*\* file: [^*]+, line: \d+ \*\/).*<\?php#ms', $match[0], $comments);
                $comment = $comments[1] ?? '';
            }

            $phpContents[] = $comment . rtrim($match[2]);
        }

        if ($addPHPOpeningTag) {
            array_unshift($phpContents, '<?php');
        }

        return implode(PHP_EOL, $phpContents) . PHP_EOL;
    }
}
