<div class="flex items-center gap-2 rounded-lg border p-4">one</div>
<div class="flex items-center gap-2 rounded-lg border p-4">two</div>
<div class="flex items-center gap-2 rounded-lg border p-4">three</div>
<div class="flex items-center gap-2 rounded-lg border p-4">four</div>
<div x-data="{ open: false }" x-bind:class="open ? 'ring-2' : ''" class="flex items-center gap-2 rounded-lg border p-4">bound</div>
<x-tile class="mt-2">tile</x-tile>
<a href="#" class="block rounded px-2 py-1 hover:bg-gray-100 sm:gap-4">link</a>
<p class="block rounded px-2 py-1">p1</p>
<p class="block rounded px-2 py-1">p2</p>
<p class="block rounded px-2 py-1">p3</p>
