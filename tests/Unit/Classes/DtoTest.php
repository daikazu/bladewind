<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\CandidateSet;
use Daikazu\BladeWind\Classes\ClassGroup;
use Daikazu\BladeWind\Classes\ConditionalTokens;
use Daikazu\BladeWind\Classes\GroupClassification;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\RuntimeClassification;
use Daikazu\BladeWind\Classes\UnresolvedConstruct;

it('round-trips a class group with keys in a stable order', function (): void {
    $group = new ClassGroup(
        ClassGroup::ON_COMPONENT, 'x-card', ClassGroup::SOURCE_ATTRIBUTE, GroupClassification::Mixed,
        ['p-2', 'p-2'], [new ConditionalTokens(['shadow'], '$raised')], ['$extra'], false, 3, 5,
    );

    $array = $group->toArray();

    expect(array_keys($array))->toBe(['on', 'tag', 'source', 'classification', 'tokens', 'conditional', 'unresolved', 'bag', 'line', 'column'])
        ->and($array['conditional'][0])->toBe(['tokens' => ['shadow'], 'condition' => '$raised'])
        ->and(ClassGroup::fromArray($array))->toEqual($group);
});

it('round-trips runtime classes, candidates, and unresolved constructs', function (): void {
    $runtime = new RuntimeClasses('livewire', 'wire:loading.class.remove', RuntimeClassification::Static, true, ['opacity-100'], [], [], 4, 1);
    $candidates = new CandidateSet('alpine', ['hidden', 'block'], 6, 2);
    $unresolved = new UnresolvedConstruct('alpine', 'themeClasses[current]', 6, 2);

    expect(array_keys($runtime->toArray()))->toBe(['adapter', 'attribute', 'classification', 'remove', 'tokens', 'conditional', 'unresolved', 'line', 'column'])
        ->and(RuntimeClasses::fromArray($runtime->toArray()))->toEqual($runtime)
        ->and(array_keys($candidates->toArray()))->toBe(['origin', 'tokens', 'line', 'column'])
        ->and(CandidateSet::fromArray($candidates->toArray()))->toEqual($candidates)
        ->and(array_keys($unresolved->toArray()))->toBe(['kind', 'expression', 'line', 'column'])
        ->and(UnresolvedConstruct::fromArray($unresolved->toArray()))->toEqual($unresolved);
});
