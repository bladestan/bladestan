<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class BackedComponent extends Component
{
    public string $c;

    public function __construct(
        private readonly string $b,
        private readonly string $panelClass = '',
    ) {
    }

    public function render(): View
    {
        return $this->view('components.component', [
            'a' => 'a',
            'b' => $this->b,
            'c' => $this->c,
            'panelClass' => $this->panelClass,
        ]);
    }
}
