<div x-data="{ open: false }" class="relative">
<button type="button" class="inline-flex items-center gap-1" x-bind:class="open ? 'rotate-180' : ''" x-on:click="open = !open">{{ $slot }}</button>
<div :class="{ 'hidden': !open, 'block': open }" x-transition:enter="transition ease-out duration-100" class="absolute mt-2">menu</div>
<div :class="themeClasses[current]">theme</div>
</div>
