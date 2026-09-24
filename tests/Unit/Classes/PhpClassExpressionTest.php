<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\PhpClassExpression;

it('treats a bare string literal as static tokens', function (): void {
    $result = PhpClassExpression::enumerate("'flex items-center'");

    expect($result->staticTokens)->toBe(['flex', 'items-center'])
        ->and($result->conditions)->toBe([])
        ->and($result->resolvedFully())->toBeTrue();
});

it('enumerates both branches of a ternary under its test', function (): void {
    $result = PhpClassExpression::enumerate("\$active ? 'bg-blue-500' : 'bg-gray-500 text-sm'");

    expect($result->staticTokens)->toBe([])
        ->and(array_map(fn ($c) => [$c->tokens, $c->condition], $result->conditions))->toBe([
            [['bg-blue-500'], '$active'],
            [['bg-gray-500', 'text-sm'], '$active'],
        ])
        ->and($result->resolvedFully())->toBeTrue();
});

it('uses the nearest enclosing test for nested ternaries and parenthesised branches', function (): void {
    $result = PhpClassExpression::enumerate("\$a ? (\$b ? 'x' : 'y') : 'z'");

    expect(array_map(fn ($c) => [$c->tokens, $c->condition], $result->conditions))->toBe([
        [['x'], '$b'], [['y'], '$b'], [['z'], '$a'],
    ]);
});

it('handles short ternary and null coalesce with a literal right side', function (): void {
    $short = PhpClassExpression::enumerate("\$tone ?: 'text-gray-500'");
    $coalesce = PhpClassExpression::enumerate("\$override ?? 'p-4'");
    $both = PhpClassExpression::enumerate("'a' ?? 'b'");

    expect($short->unresolved)->toBe(['$tone'])
        ->and(array_map(fn ($c) => [$c->tokens, $c->condition], $short->conditions))->toBe([[['text-gray-500'], '$tone']])
        ->and($coalesce->unresolved)->toBe(['$override'])
        ->and(array_map(fn ($c) => [$c->tokens, $c->condition], $coalesce->conditions))->toBe([[['p-4'], '$override']])
        ->and($both->staticTokens)->toBe(['a'])
        ->and(array_map(fn ($c) => $c->tokens, $both->conditions))->toBe([['b']])
        ->and($both->resolvedFully())->toBeTrue();
});

it('skips empty-string ternary branches instead of recording an empty conditional', function (): void {
    $oneEmpty = PhpClassExpression::enumerate("\$a ? 'x' : ''");
    $bothEmpty = PhpClassExpression::enumerate("\$a ? '' : ''");

    expect(array_map(fn ($c) => [$c->tokens, $c->condition], $oneEmpty->conditions))->toBe([[['x'], '$a']])
        ->and($bothEmpty->conditions)->toBe([])
        ->and($bothEmpty->staticTokens)->toBe([])
        ->and($bothEmpty->unresolved)->toBe([]);
});

it('records variables, concatenation, interpolation, and calls as unresolved', function (): void {
    expect(PhpClassExpression::enumerate('$classes')->unresolved)->toBe(['$classes'])
        ->and(PhpClassExpression::enumerate("'bg-' . \$color . '-500'")->unresolved)->toBe(["'bg-' . \$color . '-500'"])
        ->and(PhpClassExpression::enumerate('"bg-{$color}-500"')->unresolved)->toBe(['"bg-{$color}-500"'])
        ->and(PhpClassExpression::enumerate('classes($x)')->unresolved)->toBe(['classes($x)'])
        ->and(PhpClassExpression::enumerate("\$x ? classes(\$y) : 'a'")->unresolved)->toBe(['classes($y)']);
});
