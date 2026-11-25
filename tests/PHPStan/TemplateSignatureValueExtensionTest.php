<?php

declare(strict_types=1);

namespace Bladestan\Tests\PHPStan;

use Bladestan\Compiler\SignatureExtractor;
use Bladestan\PHPStan\TemplateSignatureValueExtension;
use PHPUnit\Framework\TestCase;

final class TemplateSignatureValueExtensionTest extends TestCase
{
    private const SIGNATURE = "@php\n/**\n * @bladestan-signature\n * @var string \$title\n */\n@endphp\n";

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bladestan-signature-value-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testBodyEditLeavesTheValueAlone(): void
    {
        $template = $this->write('page.blade.php', self::SIGNATURE . "<h1>{{ \$title }}</h1>\n");
        $before = $this->extension()
            ->getValue($template);

        $this->write('page.blade.php', self::SIGNATURE . "<h2>{{ \$title }}</h2>\n<p>More body</p>\n");

        $this->assertSame($before, $this->extension()->getValue($template));
    }

    public function testSignatureEditChangesTheValue(): void
    {
        $template = $this->write('page.blade.php', self::SIGNATURE . "<h1>{{ \$title }}</h1>\n");
        $before = $this->extension()
            ->getValue($template);

        $this->write('page.blade.php', str_replace('string', 'int', self::SIGNATURE) . "<h1>{{ \$title }}</h1>\n");

        $this->assertNotSame($before, $this->extension()->getValue($template));
    }

    public function testExtendsEditChangesTheValue(): void
    {
        $template = $this->write('page.blade.php', "@extends('layouts.one')\n<h1>Body</h1>\n");
        $before = $this->extension()
            ->getValue($template);

        $this->write('page.blade.php', "@extends('layouts.two')\n<h1>Body</h1>\n");

        $this->assertNotSame($before, $this->extension()->getValue($template));
    }

    public function testMissingTemplateHasAValueOfItsOwn(): void
    {
        $missing = $this->directory . '/missing.blade.php';
        $empty = $this->write('empty.blade.php', '');

        $this->assertSame('missing', $this->extension()->getValue($missing));
        $this->assertNotSame('missing', $this->extension()->getValue($empty));
    }

    public function testKeyInsideTheProjectIsStoredRelativeToIt(): void
    {
        $templateSignatureValueExtension = new TemplateSignatureValueExtension(new SignatureExtractor(), '/project');

        $this->assertSame('resources/views/page.blade.php', $templateSignatureValueExtension->keyToResultCache('/project/resources/views/page.blade.php'));
        $this->assertSame('/project/resources/views/page.blade.php', $templateSignatureValueExtension->keyFromResultCache('resources/views/page.blade.php'));
    }

    public function testKeyOutsideTheProjectStaysAbsolute(): void
    {
        $templateSignatureValueExtension = new TemplateSignatureValueExtension(new SignatureExtractor(), '/project');

        $this->assertSame('/elsewhere/page.blade.php', $templateSignatureValueExtension->keyToResultCache('/elsewhere/page.blade.php'));
        $this->assertSame('/elsewhere/page.blade.php', $templateSignatureValueExtension->keyFromResultCache('/elsewhere/page.blade.php'));
    }

    /**
     * A fresh instance per read, since one instance remembers its values for
     * the rest of the run.
     */
    private function extension(): TemplateSignatureValueExtension
    {
        return new TemplateSignatureValueExtension(new SignatureExtractor(), $this->directory);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
