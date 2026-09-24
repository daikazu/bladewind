@props(['tone' => 'neutral'])
<span {{ $attributes->class(['inline-flex rounded-full px-2', 'bg-gray-100' => $tone === 'neutral']) }} wire:loading.class="opacity-50" wire:loading.class.remove="opacity-100" wire:dirty.class="border-yellow-500">
{{ $slot }}
</span>
