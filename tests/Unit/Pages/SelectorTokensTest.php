<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\SelectorTokens;

it('names every class in a selector list, unescaped and unique', function (): void {
    expect(SelectorTokens::tokens('.flex,.items-center'))->toBe(['flex', 'items-center'])
        ->and(SelectorTokens::tokens('.hover\:bg-gray-100:hover'))->toBe(['hover:bg-gray-100'])
        ->and(SelectorTokens::tokens('.group-hover\:flex:is(:where(.group):hover *)'))->toBe(['group-hover:flex', 'group'])
        ->and(SelectorTokens::tokens(':where(.space-y-6>:not(:last-child))'))->toBe(['space-y-6'])
        ->and(SelectorTokens::tokens('.\32 xl\:p-4'))->toBe(['2xl:p-4'])
        ->and(SelectorTokens::tokens('.p-4,.p-4'))->toBe(['p-4'])
        ->and(SelectorTokens::tokens('*,:before'))->toBe([]);
});

it('names only the subject classes, ignoring the markers a variant tests for', function (): void {
    expect(SelectorTokens::subjects('.dark\:bg-black:where(.dark, .dark *)'))->toBe(['dark:bg-black'])
        ->and(SelectorTokens::subjects('.peer-checked\:block:is(:where(.peer):checked~*)'))->toBe(['peer-checked:block'])
        ->and(SelectorTokens::subjects('.has-\[\.child\]\:flex:has(.child)'))->toBe(['has-[.child]:flex'])
        ->and(SelectorTokens::subjects('.group-hover\:flex:is(:where(.group):hover *)'))->toBe(['group-hover:flex'])
        ->and(SelectorTokens::subjects('.p-4,.px-4'))->toBe(['p-4', 'px-4'])
        ->and(SelectorTokens::subjects('.\[\&_p\]\:m-0 p'))->toBe(['[&_p]:m-0'])
        // No class outside a functional pseudo-class: the caller falls back to tokens().
        ->and(SelectorTokens::subjects(':where(.space-y-6>:not(:last-child))'))->toBe([])
        ->and(SelectorTokens::tokens(':where(.space-y-6>:not(:last-child))'))->toBe(['space-y-6']);
});

it('names the classes of the rightmost compound, reading inside a lone :is() and falling back to every class', function (): void {
    // Tailwind 3's variant shapes: the marker is a class on another element.
    expect(SelectorTokens::targets('.group:hover .group-hover\:flex'))->toBe(['group-hover:flex'])
        ->and(SelectorTokens::targets('.peer:checked~.peer-checked\:block'))->toBe(['peer-checked:block'])
        ->and(SelectorTokens::targets('.dark\:bg-black:is(.dark *)'))->toBe(['dark:bg-black'])
        ->and(SelectorTokens::targets(':is(.dark .dark\:bg-black)'))->toBe(['dark:bg-black'])
        ->and(SelectorTokens::targets(':is(.dark .dark\:bg-black):hover'))->toBe(['dark:bg-black'])
        ->and(SelectorTokens::targets(':is(.dark .x).b'))->toBe(['b'])
        ->and(SelectorTokens::targets('.a > .b + .c'))->toBe(['c'])
        // Tailwind 4's shapes read the same as subjects().
        ->and(SelectorTokens::targets('.dark\:bg-black:where(.dark, .dark *)'))->toBe(['dark:bg-black'])
        ->and(SelectorTokens::targets('.group-hover\:flex:is(:where(.group):hover *)'))->toBe(['group-hover:flex'])
        ->and(SelectorTokens::targets('.has-\[\.child\]\:flex:has(.child)'))->toBe(['has-[.child]:flex'])
        // No class on the matched element: every class the selector mentions.
        ->and(SelectorTokens::targets('.prose h1'))->toBe(['prose'])
        ->and(SelectorTokens::targets('.space-y-6>:not([hidden])~:not([hidden])'))->toBe(['space-y-6'])
        ->and(SelectorTokens::targets(':where(.space-y-6>:not(:last-child))'))->toBe(['space-y-6'])
        ->and(SelectorTokens::targets('.\[\&_p\]\:m-0 p'))->toBe(['[&_p]:m-0'])
        // A list is the union of its selectors; a hex escape's terminating space is no combinator.
        ->and(SelectorTokens::targets('.p-4,.px-4'))->toBe(['p-4', 'px-4'])
        ->and(SelectorTokens::targets(':is(.a,.b)'))->toBe(['a', 'b'])
        ->and(SelectorTokens::targets('.\32 xl\:p-4'))->toBe(['2xl:p-4'])
        ->and(SelectorTokens::targets('.a[data-b="] .c"] .d'))->toBe(['d'])
        ->and(SelectorTokens::targets('a:nth-child(2n+1) .e'))->toBe(['e'])
        ->and(SelectorTokens::targets('[x-cloak]'))->toBe([])
        ->and(SelectorTokens::targets('*,:before'))->toBe([]);
});

it('tells a selector list with a class-less selector from one every selector of which names a class', function (): void {
    expect(SelectorTokens::hasSelectorWithoutClass('code,.code'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('.border-box,a,article,input[type=text]'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('.a:not(.b),p'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('[x-cloak]'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('.p-4,.px-4'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass('.prose h1'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass(':where(.space-y-6>:not(:last-child))'))->toBeFalse()
        // A comma inside a functional pseudo-class or a string is not a list separator.
        ->and(SelectorTokens::hasSelectorWithoutClass('.dark\:x:where(.dark, .dark *)'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass('.a[data-x="b,c"]'))->toBeFalse()
        // A class inside :not() does not own the rule: the rule matches because the class is absent.
        ->and(SelectorTokens::hasSelectorWithoutClass('p:not(.a)'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('*:not(.hidden)'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('input:not(.a):focus'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass('body:not(.js) .menu'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass('.a:not(.b)'))->toBeFalse()
        // Nor does a class that is only one alternative of an :is()/:where() beside a class-less one.
        ->and(SelectorTokens::hasSelectorWithoutClass(':is(.a,p)'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass(':where(.a, p) span'))->toBeTrue()
        ->and(SelectorTokens::hasSelectorWithoutClass(':where(.a):hover'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass('.b:is(.a,p)'))->toBeFalse()
        ->and(SelectorTokens::hasSelectorWithoutClass(':is(.dark .x)'))->toBeFalse();
});

it('ignores dots inside attribute selectors and strings, but not escaped brackets in a token', function (): void {
    expect(SelectorTokens::tokens('a[href$=".pdf"]:after'))->toBe([])
        ->and(SelectorTokens::tokens('.a:not(.b)[data-x=".c"]'))->toBe(['a', 'b'])
        // The brackets of an arbitrary variant are escaped, so they open no attribute selector.
        ->and(SelectorTokens::tokens('.has-\[\.child\]\:flex:has(.child)'))->toBe(['has-[.child]:flex', 'child'])
        ->and(SelectorTokens::tokens('.\[\&_p\]\:m-0 p'))->toBe(['[&_p]:m-0'])
        ->and(SelectorTokens::tokens('[x-cloak]'))->toBe([])
        ->and(SelectorTokens::tokens('.a[data-b="]"] .c'))->toBe(['a', 'c']);
});

it('returns an empty list when the regex engine fails instead of throwing', function (): void {
    $previous = ini_set('pcre.backtrack_limit', '1');

    try {
        // A long run of hex-escape/literal-char ambiguity forces PCRE to exhaust the backtrack
        // limit for this selector list, so preg_match_all() returns false rather than a match count.
        $selectorList = '.'.str_repeat('\41', 200).'!';

        expect(SelectorTokens::tokens($selectorList))->toBe([]);
    } finally {
        ini_set('pcre.backtrack_limit', $previous === false ? '1000000' : $previous);
    }
});
