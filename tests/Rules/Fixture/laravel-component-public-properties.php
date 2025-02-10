<?php declare(strict_types=1);

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;

class ComponentPassingPublicProperties extends Component
{
    public function __construct(
        public string $foo,
    ) {}

    public function render(): View
    {
        return view('foo');
    }
}
