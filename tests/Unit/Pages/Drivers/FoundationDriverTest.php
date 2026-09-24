<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\FoundationDriver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

function foundationFixtureCss(): string
{
    return (string) file_get_contents(test()->fixturePath('public-foundation/build/assets/app-foundation.css'));
}

function foundationDriver(): FoundationDriver
{
    return new FoundationDriver(new FlatStylesheetSplitter);
}

it('recognises Foundation by classes only it names and lists the classes its JavaScript adds', function (): void {
    $driver = foundationDriver();

    expect($driver->name())->toBe('foundation')
        ->and($driver->detect(foundationFixtureCss()))->toBeTrue()
        ->and($driver->detect('.reveal-overlay{x:y}.grid-x{x:y}'))->toBeTrue()
        ->and($driver->detect('.reveal-overlay{x:y}'))->toBeFalse()
        ->and($driver->detect(':root{--bs-blue:#0d6efd}.btn{x:y}'))->toBeFalse()
        ->and($driver->wrapUtilities('.a{x:y}'))->toBe('.a{x:y}')
        ->and($driver->expects())->toBe('rule with a class selector')
        ->and($driver->runtimeTokens())->toContain('reveal-overlay', 'is-reveal-open', 'js-off-canvas-overlay', 'is-open', 'is-active', 'tooltip', 'is-stuck', 'is-invalid-input')
        ->and($driver->runtimeTokens())->not->toContain('reveal', 'dropdown-pane', 'off-canvas', 'button')
        ->and(array_values(array_unique($driver->runtimeTokens())))->toBe($driver->runtimeTokens())
        ->and($driver->indexTokens('.button.primary'))->toBe(['button', 'primary'])
        ->and($driver->indexTokens('.is-reveal-open body'))->toBe(['is-reveal-open'])
        ->and($driver->indexTokens('.grid-x>.small-6'))->toBe(['small-6'])
        ->and($driver->indexTokens('.menu.vertical>li>a'))->toBe(['menu', 'vertical']);
});

it('cuts the real Foundation build at its opening reveal media query, leaving the charset as the root', function (): void {
    $css = foundationFixtureCss();
    $split = foundationDriver()->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->root)->toBe('@charset "UTF-8";')
        ->and($split->utilities)->toStartWith('@media print,screen and (min-width:40em){.reveal,')
        ->and($split->utilities)->toContain('/*! normalize.css v8.0.0')
        ->and($split->utilities)->toContain('.button{')
        ->and($split->keyframes)->toBe([])
        ->and($split->propertyRules)->toBe([])
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->root.$split->utilities)->toBe($css);
});

it('attributes every rule after the cut to a token or to every page', function (): void {
    $driver = foundationDriver();
    $index = UtilityRuleIndex::build($driver->split(foundationFixtureCss())->utilities, $driver->indexTokens(...));
    $positions = [];

    foreach ($index->tokens() as $token) {
        foreach ($index->rulesFor($token) as $rule) {
            $positions[$rule->position] = true;
        }
    }

    foreach ($index->unconditional() as $rule) {
        $positions[$rule->position] = true;
    }

    $unconditional = array_map(static fn ($rule): string => $rule->selector, $index->unconditional());

    expect($index->count())->toBeGreaterThan(800)
        ->and(count($positions))->toBe($index->count())
        ->and($unconditional)->toContain('html', 'body', 'button,input,optgroup,select,textarea')
        ->and($index->rulesFor('reveal'))->not->toBeEmpty()
        ->and($index->rulesFor('reveal')[0]->wrappers)->toBe(['@media print,screen and (min-width:40em)'])
        ->and($index->rulesFor('reveal-overlay'))->not->toBeEmpty()
        ->and($index->rulesFor('primary'))->not->toBeEmpty();
});
