<?php

declare(strict_types=1);

namespace App\Contexts\Widgets;

use Livewire\Component;

/**
 * A Livewire component registered under an explicit alias (see
 * TestServiceProvider). It lives outside livewire.class_namespace on purpose:
 * the class-namespace convention can never name it, so only asking Livewire's
 * own registry resolves it.
 */
class AliasedWidget extends Component
{
    public string $label;

    public function mount(int $sourceId): void
    {
    }
}
