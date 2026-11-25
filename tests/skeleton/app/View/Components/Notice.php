<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A class component whose view is not named after it, so only the view's own
 * `@bladestan-component` tag can tie the two together.
 */
class Notice extends Component
{
    public function __construct(
        public readonly string $message,
    ) {
    }

    public function level(): int
    {
        return 1;
    }

    public function render(): View
    {
        return $this->view('notices.banner');
    }
}
