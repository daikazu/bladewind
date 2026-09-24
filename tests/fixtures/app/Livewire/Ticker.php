<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Ticker extends Component
{
    public int $count = 0;

    public function tick(): void
    {
        $this->count++;
        $this->renderIsland('stats');
    }

    public function render(): View
    {
        return view('livewire.ticker');
    }
}
