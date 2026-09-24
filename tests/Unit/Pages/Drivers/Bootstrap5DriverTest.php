<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\Bootstrap5Driver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

function bootstrap5FixtureCss(): string
{
    return (string) file_get_contents(test()->fixturePath('public-bootstrap5/build/assets/app-bootstrap5.css'));
}

function bootstrap5Driver(): Bootstrap5Driver
{
    return new Bootstrap5Driver(new FlatStylesheetSplitter);
}

it('recognises Bootstrap 5 by its variable namespace and names the classes its JavaScript adds', function (): void {
    $driver = bootstrap5Driver();

    expect($driver->name())->toBe('bootstrap5')
        ->and($driver->detect(bootstrap5FixtureCss()))->toBeTrue()
        ->and($driver->detect(':root{--bs-blue:#0d6efd}.btn{x:y}'))->toBeTrue()
        ->and($driver->detect('*,:before{--tw-a:0}.flex{display:flex}'))->toBeFalse()
        ->and($driver->detect('/*! TACHYONS v4.12.0 */.flex{display:flex}'))->toBeFalse()
        ->and($driver->wrapUtilities('.a{x:y}'))->toBe('.a{x:y}')
        ->and($driver->expects())->toBe('rule with a class selector')
        ->and($driver->runtimeTokens())->toContain('modal-backdrop', 'collapsing', 'tooltip', 'tooltip-inner', 'popover', 'show', 'fade', 'active', 'modal-open')
        ->and($driver->runtimeTokens())->not->toContain('modal', 'dropdown-menu', 'btn')
        ->and(array_values(array_unique($driver->runtimeTokens())))->toBe($driver->runtimeTokens())
        // Bootstrap's compound selectors read the class on the element the rule styles.
        ->and($driver->indexTokens('.btn-check:checked+.btn'))->toBe(['btn'])
        ->and($driver->indexTokens('.navbar-expand-lg .navbar-nav'))->toBe(['navbar-nav'])
        ->and($driver->indexTokens('.table>:not(caption)>*>*'))->toBe(['table'])
        ->and($driver->indexTokens('.accordion-button:not(.collapsed)'))->toBe(['accordion-button'])
        ->and($driver->indexTokens('.form-floating>.form-control:focus~label'))->toBe(['form-floating', 'form-control'])
        ->and($driver->indexTokens('[data-bs-theme=dark] .btn-close'))->toBe(['btn-close']);
});

it('cuts the real Bootstrap build after hr, keeps the variables in the root and lifts the keyframes', function (): void {
    $css = bootstrap5FixtureCss();
    $split = bootstrap5Driver()->split($css);

    expect($split->found)->toBeTrue()
        ->and($split->root)->toStartWith('@charset "UTF-8";/*!')
        ->and($split->root)->toContain(':root,[data-bs-theme=light]{--bs-blue:#0d6efd;')
        ->and($split->root)->toContain('[data-bs-theme=dark]{')
        ->and($split->root)->toContain('*,::after,::before{box-sizing:border-box}')
        ->and($split->root)->toContain('body{')
        ->and($split->root)->toEndWith('}')
        ->and($split->root)->not->toContain('.h1')
        // Type is merged with reboot: the first class rule is the headings list, and the rest of
        // reboot (p, abbr, a, ...) follows it into the utilities as unconditional rules.
        ->and($split->utilities)->toStartWith('.h1,.h2,.h3,.h4,.h5,.h6,h1,h2,h3,h4,h5,h6{')
        ->and($split->utilities)->toContain('.btn{')
        ->and($split->utilities)->not->toContain('@keyframes')
        ->and(array_keys($split->keyframes))->toContain('spinner-border', 'spinner-grow', 'progress-bar-stripes', 'placeholder-glow', 'placeholder-wave')
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->theme)->toBe([])
        ->and($split->propertyRules)->toBe([])
        ->and(strlen($split->root) + strlen($split->utilities) + strlen(implode('', $split->keyframes)))->toBe(strlen($css));
});

it('attributes every rule after the cut to a token or to every page', function (): void {
    $driver = bootstrap5Driver();
    $index = UtilityRuleIndex::build($driver->split(bootstrap5FixtureCss())->utilities, $driver->indexTokens(...));
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

    expect($index->count())->toBeGreaterThan(2000)
        ->and(count($positions))->toBe($index->count())
        // Reboot's element rules after the cut, and the heading lists that merge a class with an element.
        ->and($unconditional)->toContain('p', 'a', '.h1,.h2,.h3,.h4,.h5,.h6,h1,h2,h3,h4,h5,h6')
        ->and($index->rulesFor('h1'))->toBe([])
        ->and($index->rulesFor('btn'))->not->toBeEmpty()
        ->and($index->rulesFor('modal-backdrop'))->not->toBeEmpty()
        ->and($index->rulesFor('navbar-expand-lg'))->not->toBeEmpty();
});
