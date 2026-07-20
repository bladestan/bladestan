<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

class WiredComponent extends Component
{
    public string $c;

    public int $accountId;

    public string $scopeType;

    public function mount(int $b): void
    {
    }
}
