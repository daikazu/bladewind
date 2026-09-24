@props(['title' => null])
<div {{ $attributes->class(['rounded-xl border p-6']) }}>
    @isset($header)<div class="font-semibold">{{ $header }}</div>@endisset
    {{ $slot }}
</div>
