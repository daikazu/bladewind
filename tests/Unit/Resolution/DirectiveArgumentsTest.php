<?php

declare(strict_types=1);

use Daikazu\BladeWind\Resolution\ArgumentKind;
use Daikazu\BladeWind\Resolution\DirectiveArguments;

it('returns no arguments for null or empty input', function (): void {
    expect(DirectiveArguments::split(null))->toBe([])
        ->and(DirectiveArguments::split('()'))->toBe([])
        ->and(DirectiveArguments::split('(   )'))->toBe([]);
});

it('extracts single-quoted and double-quoted literals', function (): void {
    $arguments = DirectiveArguments::split("('partials.footer', \"layouts.app\")");

    expect($arguments)->toHaveCount(2)
        ->and($arguments[0]->kind)->toBe(ArgumentKind::Literal)
        ->and($arguments[0]->value)->toBe('partials.footer')
        ->and($arguments[1]->value)->toBe('layouts.app');
});

it('unescapes quotes inside single-quoted literals', function (): void {
    expect(DirectiveArguments::split("('it\\'s')")[0]->value)->toBe("it's");
});

it('splits only on top-level commas', function (): void {
    $arguments = DirectiveArguments::split("(\$items->where('a', 1), 'partials.item', ['x' => fn () => [1, 2]])");

    expect($arguments)->toHaveCount(3)
        ->and($arguments[0]->kind)->toBe(ArgumentKind::Expression)
        ->and($arguments[0]->source)->toBe("\$items->where('a', 1)")
        ->and($arguments[1]->kind)->toBe(ArgumentKind::Literal)
        ->and($arguments[1]->value)->toBe('partials.item')
        ->and($arguments[2]->kind)->toBe(ArgumentKind::ArrayLiteral);
});

it('splits array literals into elements', function (): void {
    $arguments = DirectiveArguments::split("(['partials.custom', 'partials.footer', \$dynamic], \$data)");

    expect($arguments[0]->kind)->toBe(ArgumentKind::ArrayLiteral)
        ->and(array_map(fn ($a) => $a->kind, $arguments[0]->elements))->toBe([
            ArgumentKind::Literal, ArgumentKind::Literal, ArgumentKind::Expression,
        ])
        ->and($arguments[0]->elements[1]->value)->toBe('partials.footer');
});

it('treats interpolated strings, concatenation, and variables as expressions', function (): void {
    $arguments = DirectiveArguments::split('("pages.$name", \'a\' . $b, $view)');

    expect(array_map(fn ($a) => $a->kind, $arguments))->toBe([
        ArgumentKind::Expression, ArgumentKind::Expression, ArgumentKind::Expression,
    ]);
});

it('ignores trailing commas', function (): void {
    expect(DirectiveArguments::split("('a', 'b',)"))->toHaveCount(2);
});

it('preserves whitespace in array element sources', function (): void {
    $arguments = DirectiveArguments::split("(['rounded-lg p-4', 'shadow' => \$raised, \$user->isAdmin() && ! \$x])");

    expect(array_map(fn ($a) => $a->source, $arguments[0]->elements))->toBe([
        "'rounded-lg p-4'",
        "'shadow' => \$raised",
        '$user->isAdmin() && ! $x',
    ]);
});
