<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;

final class Counter extends Component
{
    public int $count = 0;

    public function render(): string
    {
        return '<div class="p-2">{{ $count }}</div>';
    }
}
