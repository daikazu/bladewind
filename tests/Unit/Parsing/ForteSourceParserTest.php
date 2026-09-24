<?php

declare(strict_types=1);

use Daikazu\BladeWind\Parsing\ForteSourceParser;
use Daikazu\BladeWind\Parsing\Nodes\Attribute;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\Block;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Directive;
use Daikazu\BladeWind\Parsing\Nodes\EchoStatement;
use Daikazu\BladeWind\Parsing\Nodes\Element;
use Daikazu\BladeWind\Parsing\Nodes\Node;
use Daikazu\BladeWind\Parsing\Nodes\Template;

/**
 * @template T of Node
 *
 * @param  class-string<T>  $class
 * @return list<T>
 */
function nodesOf(Template $template, string $class): array
{
    $found = [];
    $template->walk(function (Node $node) use (&$found, $class): void {
        if ($node instanceof $class) {
            $found[] = $node;
        }
    });

    return $found;
}

function attributeNamed(Element|ComponentTag $node, string $name): Attribute
{
    foreach ($node->attributes as $attribute) {
        if ($attribute->name === $name) {
            return $attribute;
        }
    }

    throw new RuntimeException("No attribute [{$name}].");
}

it('maps elements and static class attributes with positions', function (): void {
    $template = (new ForteSourceParser)->parse("<div>\n  <span class=\"flex items-center gap-2\">A</span>\n</div>");

    $spans = array_values(array_filter(nodesOf($template, Element::class), fn (Element $e): bool => $e->tagName === 'span'));

    expect($spans)->toHaveCount(1);
    $class = attributeNamed($spans[0], 'class');
    expect($class->kind)->toBe(BindingKind::Static)
        ->and($class->value)->toBe('flex items-center gap-2')
        ->and($class->staticTokens)->toBe(['flex', 'items-center', 'gap-2'])
        ->and($class->containsEchoes)->toBeFalse()
        ->and($spans[0]->position->startLine)->toBe(2)
        ->and($spans[0]->position->startColumn)->toBe(3);
});

it('maps component tags with prefixes, slots, and binding kinds', function (): void {
    $source = <<<'BLADE'
<x-card :active="$active" wire:loading.class="opacity-50 cursor-wait" title="Home">
    <x-slot:header>Header</x-slot:header>
    <x-button @class(['rounded-lg px-4 py-2', 'bg-blue-600' => $primary]) x-bind:class="{ 'hidden': open }" />
</x-card>
<livewire:counter />
BLADE;

    $template = (new ForteSourceParser)->parse($source);
    $tags = nodesOf($template, ComponentTag::class);

    expect(array_map(fn (ComponentTag $t): string => $t->prefix.'|'.$t->name.'|'.($t->isSlot ? 'slot' : 'tag'), $tags))->toBe([
        'x-|card|tag',
        'x-|slot:header|slot',
        'x-|button|tag',
        'livewire:|counter|tag',
    ]);

    $card = $tags[0];
    expect(attributeNamed($card, 'active')->kind)->toBe(BindingKind::Bound)
        ->and(attributeNamed($card, 'active')->value)->toBe('$active')
        ->and(attributeNamed($card, 'wire:loading.class')->kind)->toBe(BindingKind::Static)
        ->and(attributeNamed($card, 'wire:loading.class')->staticTokens)->toBe(['opacity-50', 'cursor-wait'])
        ->and(attributeNamed($card, 'title')->value)->toBe('Home');

    $button = $tags[2];
    $construct = array_values(array_filter($button->attributes, fn (Attribute $a): bool => $a->kind === BindingKind::BladeConstruct));
    expect($construct)->toHaveCount(1)
        ->and($construct[0]->constructName)->toBe('class')
        ->and($construct[0]->constructArguments)->toContain('rounded-lg px-4 py-2')
        ->and(attributeNamed($button, 'x-bind:class')->kind)->toBe(BindingKind::Static);
});

it('flags attribute values that contain echoes as dynamic', function (): void {
    $template = (new ForteSourceParser)->parse('<span class="{{ $active ? \'a\' : \'b\' }}"></span><div class="bg-{{ $color }}-500" :class="theme[x]"></div>');
    $elements = nodesOf($template, Element::class);

    $span = attributeNamed($elements[0], 'class');
    expect($span->kind)->toBe(BindingKind::Static)
        ->and($span->containsEchoes)->toBeTrue()
        ->and($span->staticTokens)->toBeNull();

    $divAttributes = $elements[1]->attributes;
    expect($divAttributes[0]->containsEchoes)->toBeTrue()
        ->and($divAttributes[1]->kind)->toBe(BindingKind::Bound)
        ->and($divAttributes[1]->name)->toBe('class')
        ->and($divAttributes[1]->value)->toBe('theme[x]');
});

it('maps directives including unknown ones, roles, and block structure', function (): void {
    $source = "@extends('layouts.app')\n@if(\$a)\n@include('a')\n@else\n@livewire('counter')\n@endif\n{{ \$x }}{{-- c --}}@php \$z = 1; @endphp";
    $template = (new ForteSourceParser)->parse($source);

    $directives = nodesOf($template, Directive::class);
    expect(array_map(fn (Directive $d): string => $d->name.':'.$d->role->value, $directives))->toBe([
        'extends:standalone', 'if:opening', 'include:standalone', 'else:intermediate', 'livewire:standalone', 'endif:closing',
    ])
        ->and($directives[0]->arguments)->toBe("('layouts.app')")
        ->and($directives[4]->arguments)->toBe("('counter')")
        ->and($directives[5]->arguments)->toBeNull();

    expect(nodesOf($template, Block::class))->toHaveCount(1)
        ->and(nodesOf($template, EchoStatement::class))->toHaveCount(1)
        ->and(nodesOf($template, EchoStatement::class)[0]->content)->toBe('{{ $x }}')
        ->and($template->parseErrors)->toBe([]);
});

it('never throws on malformed input', function (): void {
    $template = (new ForteSourceParser)->parse('<div class="x">@if($a) <x-foo');

    expect($template)->toBeInstanceOf(Template::class);
});

it('exposes echo constructs in attribute position and raw attribute names', function (): void {
    $template = (new ForteSourceParser)->parse('<div {{ $attributes->class([\'p-4\']) }} {{ $attributes }} :class="{ a: b }" ::class="{ c: d }"></div><x-chip :$tone />');
    $div = nodesOf($template, Element::class)[0];
    $chip = nodesOf($template, ComponentTag::class)[0];

    $constructs = array_values(array_filter($div->attributes, fn (Attribute $a): bool => $a->kind === BindingKind::BladeConstruct));

    expect($constructs)->toHaveCount(2)
        ->and($constructs[0]->constructName)->toBe('echo')
        ->and($constructs[0]->constructArguments)->toBe("{{ \$attributes->class(['p-4']) }}")
        ->and($constructs[1]->constructArguments)->toBe('{{ $attributes }}')
        ->and($div->attributes[2]->rawName())->toBe(':class')
        ->and($div->attributes[3]->rawName())->toBe('::class')
        ->and($chip->attributes[0]->rawName())->toBe(':$tone');
});
