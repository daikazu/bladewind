<?php

declare(strict_types=1);

use App\View\Components\Alert;
use Daikazu\BladeWind\Analysis\DependencyExtractor;
use Daikazu\BladeWind\Analysis\Extraction;
use Daikazu\BladeWind\Manifest\Dependency;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Parsing\SourceParser;

function extractFixture(string $relative): Extraction
{
    $source = file_get_contents(test()->fixturePath($relative));

    return app(DependencyExtractor::class)->extract(app(SourceParser::class)->parse($source));
}

function edges(Extraction $extraction): array
{
    return array_map(
        fn (Dependency $d): string => implode('|', [$d->type->value, (string) $d->target, $d->resolution->value, $d->resolvedPath === null ? '-' : basename($d->resolvedPath), $d->line]),
        $extraction->dependencies,
    );
}

it('extracts every directive form from the home page', function (): void {
    $extraction = extractFixture('views/pages/home.blade.php');

    expect(edges($extraction))->toBe([
        'extends|layouts.app|static|app.blade.php|1',
        'component|card|static|card.blade.php|3',
        'component|button|static|button.blade.php|5',
        'include|partials.footer|static|footer.blade.php|7',
        'include|partials.optional|static|-|8',
        'include|partials.footer|static|footer.blade.php|9',
        'include|partials.footer|static|footer.blade.php|10',
        'include-first|partials.custom|static|-|11',
        'include-first|partials.footer|static|footer.blade.php|11',
        'each|partials.item|static|item.blade.php|12',
        'each|partials.empty|static|empty.blade.php|12',
    ])
        ->and(array_map(fn ($d) => $d->code, $extraction->diagnostics))->toBe([]);
});

it('extracts components, livewire, dynamic, legacy, and unresolved edges from the about page', function (): void {
    $extraction = extractFixture('views/pages/about.blade.php');

    expect(edges($extraction))->toBe([
        'component|layout|static|layout.blade.php|1',
        'component|forms.input|static|input.blade.php|2',
        'component|icon|static|index.blade.php|3',
        'component|ui::badge|static|badge.blade.php|4',
        'component|alert|static|-|5',
        'dynamic-component||unresolved|-|6',
        'dynamic-component|button|static|button.blade.php|7',
        'livewire|counter|static|-|8',
        'livewire|counter|static|-|9',
        'legacy-component|partials.legacy|static|legacy.blade.php|10',
        'component|missing|unresolved|-|13',
        'include||unresolved|-|14',
        'include-first||unresolved|-|15',
        'include-first|partials.footer|static|footer.blade.php|15',
        'include|partials.nope|static|-|16',
    ]);

    $alert = $extraction->dependencies[4];
    expect($alert->resolvedClass)->toBe(Alert::class);

    expect(array_map(fn ($d) => $d->code.'@'.$d->line, $extraction->diagnostics))->toBe([
        'BW1005@5', 'BW1004@6', 'BW1003@13', 'BW1001@14', 'BW1001@15', 'BW1002@16',
    ]);
});

it('ignores slots, unknown prefixes, and directives without targets', function (): void {
    $extraction = app(DependencyExtractor::class)->extract(app(SourceParser::class)->parse(
        "<x-card><x-slot:a>x</x-slot:a></x-card>\n<flux:button />\n@include()\n@section('x')"
    ));

    expect(array_map(fn (Dependency $d) => $d->type, $extraction->dependencies))->toBe([DependencyType::Component, DependencyType::Include])
        ->and($extraction->dependencies[1]->resolution)->toBe(Resolution::Unresolved);
});

it('raises exactly one BW1002 for @includeFirst only when no candidate resolves and there is no expression candidate', function (): void {
    $noneResolve = app(DependencyExtractor::class)->extract(app(SourceParser::class)->parse(
        "@includeFirst(['partials.nope-a', 'partials.nope-b'])"
    ));

    expect(array_map(fn ($d) => $d->code, $noneResolve->diagnostics))->toBe(['BW1002'])
        ->and($noneResolve->diagnostics[0]->message)->toContain('partials.nope-a, partials.nope-b');

    $withExpressionCandidate = app(DependencyExtractor::class)->extract(app(SourceParser::class)->parse(
        '@includeFirst([\'partials.nope\', $x])'
    ));

    expect(array_map(fn ($d) => $d->code, $withExpressionCandidate->diagnostics))->not->toContain('BW1002');
});

it('does not create an each edge for a raw fourth argument', function (): void {
    $extraction = app(DependencyExtractor::class)->extract(app(SourceParser::class)->parse(
        "@each('partials.item', \$items, 'item', 'raw|Nothing')"
    ));

    expect(array_map(fn (Dependency $d) => $d->type, $extraction->dependencies))->toBe([DependencyType::Each])
        ->and($extraction->dependencies[0]->target)->toBe('partials.item');
});
