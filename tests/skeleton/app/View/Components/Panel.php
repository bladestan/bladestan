<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Panel extends Component
{
    public function __construct(
        public readonly string $heading,
    ) {
    }

    // Public zero-argument method: Blade exposes it as a $badge closure.
    public function badge(): string
    {
        return 'new';
    }

    // Requires an argument, so Blade does not expose it as a view variable.
    public function format(int $number): string
    {
        return (string) $number;
    }

    public function render(): View
    {
        return $this->view('components.panel', [
            'heading' => $this->heading,
        ]);
    }
}
