<?php

use Livewire\Component;

new class extends Component
{
    public int $count = 0;
};
?>

<div class="flex items-center gap-2 rounded-lg border p-4"><span wire:loading.class="hover:bg-gray-100">zap {{ $count }}</span></div>
