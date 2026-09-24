<div class="flex items-center gap-2 rounded-lg border p-4" x-data="{ open: false }">
    <button wire:click="tick" wire:loading.class="opacity-50" class="rounded px-2 py-1">tick</button>
    <span class="block rounded px-2 py-1">{{ $count }}</span>
    @island(name: 'stats')
        <p class="block rounded px-2 py-1">count {{ $count }}</p>
    @endisland
</div>
