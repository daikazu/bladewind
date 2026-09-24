<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\BulmaDriver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

function bulmaFixtureCss(): string
{
    return (string) file_get_contents(test()->fixturePath('public-bulma/build/assets/app-bulma.css'));
}

function bulmaDriver(): BulmaDriver
{
    return new BulmaDriver(new FlatStylesheetSplitter);
}

it('recognises Bulma 1 by its variable namespace and reads its compound selectors by target', function (): void {
    $driver = bulmaDriver();

    expect($driver->name())->toBe('bulma')
        ->and($driver->detect(bulmaFixtureCss()))->toBeTrue()
        ->and($driver->detect(':root{--bulma-scheme-h:221}.button{x:y}'))->toBeTrue()
        ->and($driver->detect(':root{--bs-blue:#0d6efd}.btn{x:y}'))->toBeFalse()
        ->and($driver->detect('*,:before{--tw-a:0}.flex{display:flex}'))->toBeFalse()
        ->and($driver->wrapUtilities('.a{x:y}'))->toBe('.a{x:y}')
        ->and($driver->expects())->toBe('rule with a class selector')
        ->and($driver->runtimeTokens())->toBe([])
        ->and($driver->indexTokens('.button.is-primary'))->toBe(['button', 'is-primary'])
        ->and($driver->indexTokens('.navbar-item.has-dropdown .navbar-dropdown'))->toBe(['navbar-dropdown'])
        ->and($driver->indexTokens('.tabs li.is-active a'))->toBe(['tabs', 'is-active'])
        ->and($driver->indexTokens('.navbar-burger.is-active span:nth-child(1)'))->toBe(['navbar-burger', 'is-active']);
});

it('cuts the real Bulma build at the theme rule, keeping the :root variables and their colour-scheme variants in the root', function (): void {
    $css = bulmaFixtureCss();
    $split = bulmaDriver()->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->root)->toStartWith('@charset "UTF-8";')
        ->and($split->root)->toContain('/*! bulma.io v1.0')
        ->and($split->root)->toContain(':root{--bulma-control-radius:')
        ->and($split->root)->toContain('@media (prefers-color-scheme:light){:root{')
        ->and($split->root)->toContain('@media (prefers-color-scheme:dark){:root{')
        ->and($split->root)->not->toContain('.theme-light')
        ->and($split->utilities)->toStartWith('.theme-light,[data-theme=light]{')
        ->and($split->utilities)->toContain('/*! minireset.css')
        ->and($split->utilities)->toContain('.button{')
        ->and($split->utilities)->not->toContain('@keyframes')
        ->and(array_keys($split->keyframes))->toBe(['spinAround', 'pulsate', 'moveIndeterminate'])
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->theme)->toBe([])
        ->and($split->propertyRules)->toBe([])
        ->and(strlen($split->root) + strlen($split->utilities) + strlen(implode('', $split->keyframes)))->toBe(strlen($css));
});

it('attributes every rule after the cut to a token or to every page', function (): void {
    $driver = bulmaDriver();
    $index = UtilityRuleIndex::build($driver->split(bulmaFixtureCss())->utilities, $driver->indexTokens(...));
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

    expect($index->count())->toBeGreaterThan(2500)
        ->and(count($positions))->toBe($index->count())
        // The theme rules (a class beside an attribute selector) and minireset (elements only).
        ->and($unconditional)->toContain('.theme-light,[data-theme=light]', '.theme-dark,[data-theme=dark]', 'html', '*,:after,:before')
        ->and($index->rulesFor('theme-light'))->toBe([])
        ->and($index->rulesFor('button'))->not->toBeEmpty()
        ->and($index->rulesFor('is-primary'))->not->toBeEmpty()
        ->and($index->rulesFor('navbar-dropdown'))->not->toBeEmpty();
});
