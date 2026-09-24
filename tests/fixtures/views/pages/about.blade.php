<x-layout>
<x-forms.input name="q" />
<x-icon />
<x-ui::badge>New</x-ui::badge>
<x-alert type="info">Hello</x-alert>
<x-dynamic-component :component="$component" />
<x-dynamic-component component="button" />
<livewire:counter />
@livewire('counter')
@component('partials.legacy')
Legacy
@endcomponent
<x-missing />
@include($dynamic)
@includeFirst([$first, 'partials.footer'])
@include('partials.nope')
</x-layout>
