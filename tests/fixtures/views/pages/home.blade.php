@extends('layouts.app')
@section('content')
<x-card title="Home">
<x-slot:header>Header</x-slot:header>
<x-button>Save</x-button>
</x-card>
@include('partials.footer')
@includeIf('partials.optional')
@includeWhen(true, 'partials.footer')
@includeUnless(false, 'partials.footer')
@includeFirst(['partials.custom', 'partials.footer'])
@each('partials.item', $items, 'item', 'partials.empty')
@endsection
