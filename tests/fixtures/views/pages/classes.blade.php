<x-layout>
<span class="{{ $active ? 'bg-blue-500' : 'bg-gray-500' }}">A</span>
<span class="p-2 {{ $extra }}">B</span>
<span class="bg-{{ $color }}-500">C</span>
<x-chip :class="$tone === 'a' ? 'text-red-500' : 'text-green-500'">D</x-chip>
<x-chip ::class="{ 'ring': focused }">E</x-chip>
@php $fallback = 'text-gray-400 italic'; @endphp
<x-dynamic-component :component="$widget" />
</x-layout>
