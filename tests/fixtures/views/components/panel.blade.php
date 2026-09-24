@props(['raised' => false, 'bordered' => false, 'extra' => ''])
<section @class(['rounded-lg p-4', 'shadow' => $raised, $extra]) {{ $attributes->merge(['class' => 'bg-white']) }}>
<div class="{{ Arr::toCssClasses(['border' => $bordered]) }}">{{ $slot }}</div>
</section>
