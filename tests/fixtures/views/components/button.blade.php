@blaze
@props(['type' => 'button'])
<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white']) }}>{{ $slot }}</button>
