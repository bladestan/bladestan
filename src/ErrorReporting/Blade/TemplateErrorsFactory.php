<?php

declare(strict_types=1);

namespace Bladestan\ErrorReporting\Blade;

use Bladestan\ValueObject\PhpFileContentsWithLineMap;
use PHPStan\Analyser\Error;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

final class TemplateErrorsFactory
{
    public function createError(
        string $message,
        string $identifier,
        int $phpFileLine,
        string $phpFilePath,
    ): IdentifierRuleError {
        return RuleErrorBuilder::message($message)
            ->file($phpFilePath)
            ->line($phpFileLine)
            ->identifier($identifier)
            ->build();
    }

    /**
     * @param Error[] $errors
     * @return list<IdentifierRuleError>
     */
    public function createErrors(
        array $errors,
        int $phpFileLine,
        string $phpFilePath,
        PhpFileContentsWithLineMap $phpFileContentsWithLineMap
    ): array {
        $ruleErrors = [];

        foreach ($phpFileContentsWithLineMap->errors as $error) {
            $ruleErrors[] = $this->createError($error[0], $error[1], $phpFileLine, $phpFilePath);
        }

        $phpToTemplateLines = $phpFileContentsWithLineMap->phpToTemplateLines;

        foreach ($errors as $error) {
            $phpLineNumberInTemplate = (int) $error->getLine();

            $fileNameAndTemplateLine = $phpToTemplateLines[$phpLineNumberInTemplate] ?? null;

            if ($fileNameAndTemplateLine === null) {
                $fileNameAndTemplateLine = $this->resolveNearestPhpLine($phpToTemplateLines, $phpLineNumberInTemplate);
            }

            assert($error->getIdentifier() !== null);

            $ruleErrors[] = RuleErrorBuilder::message($error->getMessage())
                ->file($phpFilePath)
                ->line($phpFileLine)
                ->metadata([
                    'template_file_path' => array_key_first($fileNameAndTemplateLine),
                    'template_line' => current($fileNameAndTemplateLine),
                ])
                ->identifier($error->getIdentifier())
                ->build();
        }

        return $ruleErrors;
    }

    /**
     * Sometimes one template line can be replaced with 2 PHP lines.
     * For example `foreach` loop. This method tries to find the previous
     * template line in that case.
     *
     * @param array<int, array<string, int>> $phpToTemplateLines
     *
     * @return array<string, int>
     */
    private function resolveNearestPhpLine(array $phpToTemplateLines, int $desiredLine): array
    {
        $lastTemplateLine = [
            '' => 1,
        ];

        foreach ($phpToTemplateLines as $phpLine => $templateLine) {
            if ($desiredLine > $phpLine) {
                $lastTemplateLine = $templateLine;
                continue;
            }

            return $lastTemplateLine;
        }

        return $lastTemplateLine;
    }
}
