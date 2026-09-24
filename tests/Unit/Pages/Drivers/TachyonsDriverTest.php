<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\Drivers\TachyonsDriver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\UtilityRuleIndex;

function tachyonsFixtureCss(): string
{
    return (string) file_get_contents(test()->fixturePath('public-tachyons/build/assets/app-tachyons.css'));
}

function tachyonsDriver(): TachyonsDriver
{
    return new TachyonsDriver(new FlatStylesheetSplitter);
}

it('recognises Tachyons by its banner, or by a class only it names, and describes itself', function (): void {
    $driver = tachyonsDriver();

    expect($driver->name())->toBe('tachyons')
        ->and($driver->detect(tachyonsFixtureCss()))->toBeTrue()
        ->and($driver->detect('/*! TACHYONS v4.12.0 | http://tachyons.io */html{x:y}'))->toBeTrue()
        ->and($driver->detect('/* tachyons v4 */html{x:y}'))->toBeTrue()
        // Comments stripped: the aspect-ratio helper is the signature.
        ->and($driver->detect('html{x:y}.aspect-ratio--16x9{padding-bottom:56.25%}'))->toBeTrue()
        ->and($driver->detect('*,:before{--tw-a:0}.flex{display:flex}'))->toBeFalse()
        ->and($driver->detect('.a{x:y}'))->toBeFalse()
        ->and($driver->wrapUtilities('.a{x:y}'))->toBe('.a{x:y}')
        ->and($driver->wrapUtilities(''))->toBe('')
        ->and($driver->expects())->toBe('rule with a class selector')
        // The two-element helpers put the marker on the parent; the rule is about the child.
        ->and($driver->indexTokens('.hide-child:hover .child'))->toBe(['child'])
        ->and($driver->indexTokens('.hover-white:focus,.hover-white:hover'))->toBe(['hover-white'])
        ->and($driver->indexTokens('.debug *'))->toBe(['debug'])
        ->and($driver->indexTokens('img'))->toBe([]);
});

it('cuts the real Tachyons build at its first class rule, inside normalize', function (): void {
    $css = tachyonsFixtureCss();
    $split = tachyonsDriver()->split($css);

    // `.border-box` shares a selector list with the elements normalize sizes (the minifier merged
    // and sorted them), so the cut falls there, and what normalize declares after it
    // (`img{max-width:100%}`) is unconditional.
    expect($split->found)->toBeTrue()
        ->and($split->root)->toStartWith('/*! TACHYONS v4.12')
        ->and($split->root)->toContain('html{')
        ->and($split->root)->not->toContain('.border-box')
        ->and($split->utilities)->toStartWith('.border-box,a,article,aside,blockquote,body,code,')
        ->and($split->utilities)->toContain('input[type=url],legend,li,main,nav,ol,p,pre,section,table,td,textarea,th,tr,ul{box-sizing:border-box}')
        ->and($split->utilities)->toContain('img{max-width:100%}')
        ->and($split->utilities)->toContain('.flex{display:flex}')
        ->and($split->keyframes)->toBe([])
        ->and($split->propertyRules)->toBe([])
        ->and($split->theme)->toBe([])
        ->and($split->properties)->toBeNull()
        ->and($split->supportShaken)->toBeTrue()
        ->and($split->statements)->toBe([])
        ->and($split->root.$split->utilities)->toBe($css);
});

it('attributes every rule after the cut to a token or to every page', function (): void {
    $driver = tachyonsDriver();
    $index = UtilityRuleIndex::build($driver->split(tachyonsFixtureCss())->utilities, $driver->indexTokens(...));
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

    expect($index->count())->toBeGreaterThan(1000)
        ->and(count($positions))->toBe($index->count())
        ->and($unconditional)->toContain('img')
        // The minifier merged `.border-box` and `.code` into element lists: those rules size and
        // style every element they name, so no class token owns them and every page carries them.
        ->and($unconditional)->toContain('.code,code')
        ->and(array_filter($unconditional, static fn (string $selector): bool => str_starts_with($selector, '.border-box,a,article')))->toHaveCount(1)
        ->and($index->rulesFor('border-box'))->toBe([])
        ->and($index->rulesFor('code'))->toBe([])
        // The marker classes of the two-element helpers own no rule of their own.
        ->and($index->rulesFor('hide-child'))->toBe([])
        ->and($index->rulesFor('child'))->toHaveCount(2)
        // `:focus` and `:hover` share one rule in the minified build.
        ->and($index->rulesFor('hover-white'))->toHaveCount(1)
        ->and($index->rulesFor('hover-white')[0]->selector)->toBe('.hover-white:focus,.hover-white:hover')
        ->and($index->rulesFor('flex-ns')[0]->wrappers)->toBe(['@media screen and (min-width:30em)']);
});
