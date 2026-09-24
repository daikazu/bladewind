<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\StylesheetSplitter;
use Daikazu\BladeWind\Pages\VariableGraph;

/**
 * The graph a stylesheet's support layers build, by way of the splitter that lifts them out.
 */
function variableGraph(string $css): VariableGraph
{
    return VariableGraph::fromSplit((new StylesheetSplitter)->split($css));
}

it('reads every variable a value references, fallbacks included', function (): void {
    expect(VariableGraph::refs('color:var(--a, var(--b))'))->toBe(['--a', '--b'])
        ->and(VariableGraph::refs('.a{margin:var( --gap )}.b{padding:VAR(--gap)}.c{gap:var(--other)}'))->toBe(['--gap', '--other'])
        ->and(VariableGraph::refs('.a{color:var(--x,red)}'))->toBe(['--x'])
        ->and(VariableGraph::refs('.a{color:red}'))->toBe([])
        ->and(VariableGraph::refs(''))->toBe([]);
});

it('stops a reference at the end of the declaration when the var() is never closed', function (): void {
    expect(VariableGraph::refs('.a{color:var(--x;background:red}'))->toBe(['--x'])
        ->and(VariableGraph::refs('.a{color:var(--x}.b{color:var(--y)}'))->toBe(['--x', '--y'])
        ->and(VariableGraph::refs('.a{color:var(--x'))->toBe(['--x']);
});

it('follows a theme value to the variable it reads and stops at the end of the chain', function (): void {
    $split = (new StylesheetSplitter)->split('@layer theme{:root{--font-sans:ui-sans-serif;--default-font-family:var(--font-sans);--unused:red}}@layer utilities{.font-sans{font-family:var(--default-font-family)}}');
    $graph = VariableGraph::fromSplit($split);

    expect($graph->closure(VariableGraph::refs($split->utilities)))->toBe(['--default-font-family', '--font-sans']);
});

it('leaves a seed and a referenced name out of the closure when nothing declares them', function (): void {
    $graph = variableGraph('@layer theme{:root{--a:var(--nowhere);--b:1}}@layer utilities{.x{y:z}}');

    expect($graph->closure(['--a', '--gone']))->toBe(['--a'])
        ->and($graph->has('--nowhere'))->toBeFalse()
        ->and($graph->closure([]))->toBe([]);
});

it('keeps a name two theme blocks declare as one name with both declarations', function (): void {
    $graph = variableGraph('@layer theme{:root,:host{--color-x:red;--color-y:green}.dark{--color-x:blue}}@layer utilities{.a{color:var(--color-x)}}');

    // Both values, in stylesheet order. Which block each came from is the emitter's business, and it
    // reads that off the ThemeBlock itself rather than out of the graph.
    expect($graph->declared())->toBe(['--color-x', '--color-y'])
        ->and($graph->declarations['--color-x'])->toBe(['red', 'blue'])
        ->and($graph->declarations['--color-y'])->toBe(['green'])
        ->and($graph->closure(['--color-x']))->toBe(['--color-x']);
});

it('counts a --tw-* default and its @property registration as the one declared name', function (): void {
    $graph = variableGraph('@layer properties{@supports (a:b){*,:before{--tw-a:0}}}@layer utilities{.x{y:z}}@property --tw-a{syntax:"*";inherits:false;initial-value:0}@property --tw-b{syntax:"<length>";inherits:false;initial-value:0px}');

    // The properties-layer default and the registration's initial-value are the one name declared
    // twice, and a name only the registration declares is declared all the same.
    expect($graph->declared())->toBe(['--tw-a', '--tw-b'])
        ->and($graph->has('--tw-a'))->toBeTrue()
        ->and($graph->has('--tw-b'))->toBeTrue()
        ->and($graph->declarations['--tw-a'])->toBe(['0', '0'])
        ->and($graph->declarations['--tw-b'])->toBe(['0px']);
});

it('records an empty value for a @property registration that declares no initial value', function (): void {
    $graph = variableGraph('@layer utilities{.x{y:z}}@property --tw-a{syntax:"*";inherits:false}');

    expect($graph->declarations['--tw-a'])->toBe(['']);
});

it('declares nothing for a stylesheet whose support layers could not be taken apart', function (): void {
    $graph = variableGraph('@layer theme{:root{--x:1;color:red}}@layer utilities{.a{x:y}}');

    expect($graph->declared())->toBe([])
        ->and($graph->has('--x'))->toBeFalse()
        ->and($graph->closure(['--x']))->toBe([]);
});

it('finds the keyframes an animation declaration names', function (): void {
    $names = ['spin', 'pulse', 'ping', 'bounce'];

    expect(VariableGraph::keyframesReferenced('.animate-spin{animation:spin 1s linear infinite}', $names))->toBe(['spin'])
        ->and(VariableGraph::keyframesReferenced('.x{animation-name:pulse}', $names))->toBe(['pulse'])
        ->and(VariableGraph::keyframesReferenced('.x{-webkit-animation:bounce 1s}', $names))->toBe(['bounce'])
        ->and(VariableGraph::keyframesReferenced('.a{animation:spin 1s}.b{animation-name:pulse}', $names))->toBe(['spin', 'pulse']);
});

it('finds the keyframe a theme value names', function (): void {
    $names = ['spin', 'pulse', 'ping'];

    expect(VariableGraph::keyframesReferenced('--animate-ping:ping 1s cubic-bezier(0,0,.2,1) infinite', $names))->toBe(['ping'])
        // A utility reaching its keyframe through `var()` finds it once the theme value it resolves
        // to travels with the CSS, which is what the caller passes.
        ->and(VariableGraph::keyframesReferenced('.animate-spin{animation:var(--animate-spin)}', $names))->toBe([])
        ->and(VariableGraph::keyframesReferenced('.animate-spin{animation:var(--animate-spin)}--animate-spin:spin 1s linear infinite', $names))->toBe(['spin']);
});

it('never reads a keyframe name out of a selector or the middle of an identifier', function (): void {
    $names = ['spin', 'pulse', 'ping'];

    expect(VariableGraph::keyframesReferenced('.spin{color:red}.pulse>.ping{display:none}', $names))->toBe([])
        ->and(VariableGraph::keyframesReferenced('.x{animation:spin-slow 1s}', $names))->toBe([])
        ->and(VariableGraph::keyframesReferenced('.x{animation:spin 1s}', []))->toBe([])
        ->and(VariableGraph::keyframesReferenced('', $names))->toBe([]);
});

it('selects declared names by exact name and by prefix pattern', function (): void {
    $graph = variableGraph('@layer theme{:root{--color-brand:red;--color-accent:blue;--font-sans:ui-sans-serif}}@layer utilities{.x{y:z}}');

    expect($graph->match(['--color-*']))->toBe(['--color-accent', '--color-brand'])
        ->and($graph->match(['--font-sans']))->toBe(['--font-sans'])
        ->and($graph->match(['--font-sans', '--color-*']))->toBe(['--color-accent', '--color-brand', '--font-sans'])
        ->and($graph->match(['--color-brand', '--color-*']))->toBe(['--color-accent', '--color-brand'])
        ->and($graph->match(['--nothing', '--nothing-*']))->toBe([])
        ->and($graph->match([]))->toBe([]);
});

it('selects nothing for a pattern that does not name a custom property', function (): void {
    $graph = variableGraph('@layer theme{:root{--color-brand:red;--font-sans:ui-sans-serif}}@layer utilities{.x{y:z}}');

    // `*` would otherwise be the empty prefix and select the whole theme layer into every page file.
    expect($graph->match(['*']))->toBe([])
        ->and($graph->match(['color-*']))->toBe([])
        ->and($graph->match(['', '-', 'font-sans']))->toBe([])
        ->and($graph->match(['*', '--font-sans']))->toBe(['--font-sans']);
});

it('resolves the chains, the patterns and the keyframes of a real Tailwind build', function (): void {
    $split = (new StylesheetSplitter)->split((string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css')));
    $graph = VariableGraph::fromSplit($split);
    $closure = $graph->closure(VariableGraph::refs($split->utilities));

    expect($split->supportShaken)->toBeTrue()
        ->and($graph->has('--default-font-family'))->toBeTrue()
        ->and($graph->has('--tw-border-style'))->toBeTrue()
        ->and($graph->closure(['--default-font-family']))->toBe(['--default-font-family', '--font-sans'])
        ->and($graph->match(['--color-*']))->toBe(['--color-black', '--color-gray-100', '--color-red-500'])
        ->and($closure)->toContain('--animate-spin');

    // Every variable a declared value reads is declared too, so a closure can never hand a page a
    // reference nothing defines.
    $dangling = [];

    foreach ($graph->declarations as $values) {
        foreach ($values as $value) {
            foreach (VariableGraph::refs($value) as $reference) {
                if (! $graph->has($reference)) {
                    $dangling[] = $reference;
                }
            }
        }
    }

    // The build's only animation utility is `animation:var(--animate-spin)`, so the `spin` keyframe
    // is reachable only once the theme value that utility resolves to travels with the CSS.
    $values = '';

    foreach ($closure as $name) {
        foreach ($graph->declarations[$name] as $value) {
            $values .= $name.':'.$value.';';
        }
    }

    $keyframeNames = array_keys($split->keyframes);

    expect($dangling)->toBe([])
        ->and($keyframeNames)->toBe(['spin'])
        ->and(VariableGraph::keyframesReferenced($split->utilities, $keyframeNames))->toBe([])
        ->and(VariableGraph::keyframesReferenced($split->utilities.$values, $keyframeNames))->toBe(['spin']);
});
