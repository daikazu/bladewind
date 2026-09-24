<?php

declare(strict_types=1);

use Daikazu\BladeWind\Testing\AssertsPageStyles;
use Daikazu\BladeWind\Testing\PageExpectation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

uses(AssertsPageStyles::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/shaken', fn () => view('pages.shaken'));
    Route::get('/trait', fn () => view('pages.trait', ['color' => 'red', 'widget' => 'tile']));
});

it('passes a covered page and returns what it served', function (): void {
    // opacity-50 is only in ticker's wire:loading.class; dark:bg-black is in the stylesheet and used nowhere.
    $page = $this->assertPageStyles('/shaken', new PageExpectation(
        reaches: ['opacity-50'],
        absent: ['dark:bg-black'],
        framework: 'tailwind4',
    ));

    expect($page->fallback)->toBeFalse()
        ->and($page->delivery)->toBe('link')
        ->and($page->header['framework'])->toBe('tailwind4')
        ->and($page->rootFile)->toMatch('~^bw-root-[0-9a-f]{12}\.css$~')
        ->and($page->pageFile)->toMatch('~^bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css$~')
        ->and($page->pageCss)->toContain('.flex{display:flex}')
        ->and($page->fullCss)->toContain('.dark\:bg-black')
        ->and($page->diagnostics)->toBe([])
        // The dynamic-component wrapper and the Livewire island fragment are both compiled from strings.
        ->and($page->header['unanalysed'])->toBe('2');
});

it('fails when a reachable token has no rule in the served CSS', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(reaches: ['sm:gap-4']));
})->throws(AssertionFailedError::class, 'neither');

it('fails when a reachable token is not in the full stylesheet at all', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(reaches: ['no-such-class']));
})->throws(AssertionFailedError::class, 'not in the full stylesheet');

it('fails when an absent token has a rule in the page file', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(absent: ['flex']));
})->throws(AssertionFailedError::class, 'must not carry class [flex]');

it('fails on an unexpected diagnostic and names it', function (): void {
    $this->assertPageStyles('/trait');
})->throws(AssertionFailedError::class, 'BW2001');

it('passes when the diagnostics are exactly the expected ones', function (): void {
    $page = $this->assertPageStyles('/trait', new PageExpectation(diagnostics: ['BW1004', 'BW2001']));

    expect($page->diagnostics)->toBe(['BW1004', 'BW2001']);
});

it('fails when an expected diagnostic did not fire', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(diagnostics: ['BW2001']));
})->throws(AssertionFailedError::class, 'expected [BW2001]');

it('fails when the framework label differs', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(framework: 'tailwind3'));
})->throws(AssertionFailedError::class, 'tailwind3');

it('accepts a fallback when expected and reports the reason', function (): void {
    // With no analysable path every rendered view is unanalysed with a source, so the default policy falls back.
    config()->set('bladewind.paths', []);

    $page = $this->assertPageStyles('/shaken', new PageExpectation(fallback: true));

    expect($page->fallback)->toBeTrue()
        ->and($page->delivery)->toBe('fallback')
        ->and($page->header)->toHaveKey('fallback')
        ->and($page->rootCss)->toBe('')
        ->and($page->fullCss)->toContain('.flex{display:flex}');
});

it('fails on a fallback that was not expected', function (): void {
    config()->set('bladewind.paths', []);

    $this->assertPageStyles('/shaken');
})->throws(AssertionFailedError::class, 'fell back');

it('fails when a fallback was expected but page styles were served', function (): void {
    $this->assertPageStyles('/shaken', new PageExpectation(fallback: true));
})->throws(AssertionFailedError::class, 'expected to fall back');

it('reads the page CSS from the style element under inline delivery', function (): void {
    config()->set('bladewind.pages.delivery', 'inline');

    $page = $this->assertPageStyles('/shaken', new PageExpectation(reaches: ['opacity-50']));

    expect($page->delivery)->toBe('inline')
        ->and($page->pageCss)->toContain('.flex{display:flex}')
        ->and($page->pageFile)->toMatch('~^bw-page-[0-9a-f]{12}-'.$page->header['set'].'\.css$~')
        ->and($page->html)->toContain('<style data-bladewind-page="'.$page->header['set'].'"');
});

it('counts a view rendered from a string as unanalysed without falling back', function (): void {
    Route::get('/string', fn () => Blade::render('<x-shell><i class="px-2">s</i></x-shell>'));

    $page = $this->assertPageStyles('/string', new PageExpectation(unanalysed: 1));

    expect($page->fallback)->toBeFalse()
        ->and($page->pageCss)->toContain('.px-2{');
});

it('fails when the unanalysed count differs from the expectation', function (): void {
    Route::get('/string', fn () => Blade::render('<x-shell><i class="px-2">s</i></x-shell>'));

    $this->assertPageStyles('/string', new PageExpectation(unanalysed: 0));
})->throws(AssertionFailedError::class, 'unanalysed');
