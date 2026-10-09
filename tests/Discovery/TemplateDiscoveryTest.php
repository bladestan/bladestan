<?php

declare(strict_types=1);

namespace Bladestan\Tests\Discovery;

use Bladestan\Discovery\TemplateDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TemplateDiscoveryTest extends TestCase
{
    #[DataProvider('viewNameCases')]
    public function testDerivesViewNameFromRelativePath(string $relativePath, string $expected): void
    {
        $reflectionMethod = new ReflectionMethod(TemplateDiscovery::class, 'viewNameFromRelativePath');

        $this->assertSame($expected, $reflectionMethod->invoke(new TemplateDiscovery(), $this->nativePath($relativePath)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function viewNameCases(): iterable
    {
        yield 'top level' => ['welcome.blade.php', 'welcome'];
        yield 'nested' => ['layouts/app.blade.php', 'layouts.app'];
        yield 'deeply nested' => ['mail/orders/receipt.blade.php', 'mail.orders.receipt'];
        yield 'hyphenated filename' => ['layouts/base-layout.blade.php', 'layouts.base-layout'];

        // A literal dot in the filename is preserved rather than becoming a
        // separator: Laravel only ever resolves `foo/bar.blade.php` for the
        // view `foo.bar`, so the nested template keeps that name to itself
        // instead of a top-level `foo.bar.blade.php` shadowing it.
        yield 'dotted filename kept distinct from nested' => ['foo.bar.blade.php', 'foo.bar'];

        // Only the trailing suffix is stripped; a `.blade.php` earlier in the
        // name is left intact instead of being erased mid-string.
        yield 'suffix stripped only once' => ['weird.blade.php.blade.php', 'weird.blade.php'];
    }

    private function nativePath(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
