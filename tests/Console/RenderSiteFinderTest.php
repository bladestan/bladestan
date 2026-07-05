<?php

declare(strict_types=1);

namespace Bladestan\Tests\Console;

use Bladestan\Console\RenderSiteFinder;
use Bladestan\Console\ValueObject\RenderSite;
use PHPUnit\Framework\TestCase;

final class RenderSiteFinderTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testFindsEveryRenderKindWithDataArgument(): void
    {
        $code = <<<'PHP'
        <?php
        view('welcome', ['title' => $title]);
        \Illuminate\Support\Facades\View::make('dashboard', compact('user'));
        $factory->view('mail.receipt', ['order' => $order]);
        $mailable->markdown('mail.md', ['x' => 1]);
        PHP;

        $sites = (new RenderSiteFinder())->find($this->write($code));

        $names = array_map(fn (RenderSite $renderSite): string => $renderSite->viewName, $sites);
        sort($names);
        $this->assertSame(['dashboard', 'mail.md', 'mail.receipt', 'welcome'], $names);
    }

    public function testIgnoresCallsWithoutDataArgumentOrNonStringName(): void
    {
        $code = <<<'PHP'
        <?php
        view('no-data');
        view($dynamic, ['a' => 1]);
        PHP;

        $this->assertSame([], (new RenderSiteFinder())->find($this->write($code)));
    }

    public function testDataArgumentOffsetsCoverTheWholeExpression(): void
    {
        $code = "<?php\nview('welcome', ['title' => \$title, 'user' => \$user]);\n";

        $sites = (new RenderSiteFinder())->find($this->write($code));

        $this->assertCount(1, $sites);
        $data = substr($code, $sites[0]->dataStartPos, $sites[0]->dataEndPos - $sites[0]->dataStartPos + 1);
        $this->assertSame("['title' => \$title, 'user' => \$user]", $data);
    }

    private function write(string $code): string
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'bladestan_render_') . '.php';
        file_put_contents($this->tempFile, $code);

        return $this->tempFile;
    }
}
