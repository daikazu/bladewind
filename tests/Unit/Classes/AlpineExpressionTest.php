<?php

declare(strict_types=1);

use Daikazu\BladeWind\Classes\AlpineExpression;
use Daikazu\BladeWind\Classes\CandidateScanner;
use Daikazu\BladeWind\Classes\RuntimeClassification;

function alpineConditions(string $source): array
{
    return array_map(fn ($c) => [$c->tokens, $c->condition], AlpineExpression::scan($source)->conditions);
}

it('enumerates string and template literals', function (): void {
    expect(AlpineExpression::scan("'block hidden'")->tokens)->toBe(['block', 'hidden'])
        ->and(AlpineExpression::scan('`rotate-180`')->tokens)->toBe(['rotate-180'])
        ->and(AlpineExpression::scan("'block hidden'")->classification)->toBe(RuntimeClassification::Enumerable)
        ->and(AlpineExpression::scan('`bg-${color}-500`')->classification)->toBe(RuntimeClassification::Unresolved)
        ->and(AlpineExpression::scan('`bg-${color}-500`')->unresolved)->toBe(['`bg-${color}-500`']);
});

it('enumerates object keys under their value expressions', function (): void {
    $scan = AlpineExpression::scan("{ 'hidden': !open, block: open, ['text-red-500 font-bold']: error && touched }");

    expect($scan->tokens)->toBe(['hidden', 'block', 'text-red-500', 'font-bold'])
        ->and(alpineConditions("{ 'hidden': !open, block: open }"))->toBe([[['hidden'], '!open'], [['block'], 'open']])
        ->and($scan->classification)->toBe(RuntimeClassification::Enumerable);
});

it('enumerates arrays, ternaries, and logical chains', function (): void {
    expect(AlpineExpression::scan("['a', open ? 'b' : 'c', { d: x }]")->tokens)->toBe(['a', 'b', 'c', 'd'])
        ->and(alpineConditions("open ? 'rotate-180' : ''"))->toBe([[['rotate-180'], 'open']])
        ->and(AlpineExpression::scan("open ? 'rotate-180' : ''")->classification)->toBe(RuntimeClassification::Enumerable)
        ->and(alpineConditions("open && 'block'"))->toBe([[['block'], 'open']])
        ->and(AlpineExpression::scan("a || 'x' || 'y'")->tokens)->toBe(['x', 'y'])
        ->and(AlpineExpression::scan("theme ?? 'light'")->tokens)->toBe(['light'])
        ->and(AlpineExpression::scan("(open ? 'a' : 'b')")->tokens)->toBe(['a', 'b'])
        ->and(alpineConditions("a || b ? 'x' : 'y'"))->toBe([[['x'], 'a || b'], [['y'], 'a || b']]);
});

it('ignores condition positions and reports value positions it cannot read', function (): void {
    $partial = AlpineExpression::scan("{ 'hidden': themeClasses[current], dynamic: extra() }");
    $unresolved = AlpineExpression::scan('themeClasses[current]');
    $call = AlpineExpression::scan("open ? classes() : 'a'");

    expect($partial->tokens)->toBe(['hidden', 'dynamic'])
        ->and($partial->classification)->toBe(RuntimeClassification::Enumerable)
        ->and($unresolved->unresolved)->toBe(['themeClasses[current]'])
        ->and($unresolved->classification)->toBe(RuntimeClassification::Unresolved)
        ->and($call->unresolved)->toBe(['classes()'])
        ->and($call->classification)->toBe(RuntimeClassification::Partial)
        ->and(AlpineExpression::scan('')->classification)->toBe(RuntimeClassification::Unresolved);
});

it('unescapes only quotes and backslashes, so it enumerates the same token the candidate scan produces', function (): void {
    $source = <<<'JS'
'content-[\'\2014\']'
JS;

    expect(AlpineExpression::scan($source)->tokens)->toBe(CandidateScanner::scan($source, CandidateScanner::PHP));
});

it('reports a pair with a computed non-string key as unresolved', function (): void {
    $scan = AlpineExpression::scan("{ [key]: true, 'a': x }");

    expect($scan->tokens)->toBe(['a'])->and($scan->unresolved)->toBe(['[key]: true'])
        ->and($scan->classification)->toBe(RuntimeClassification::Partial);
});
