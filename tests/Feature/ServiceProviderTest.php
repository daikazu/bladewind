<?php

declare(strict_types=1);

use Daikazu\BladeWind\BladeWind;
use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Pages\Drivers\Bootstrap5Driver;
use Daikazu\BladeWind\Pages\Drivers\BulmaDriver;
use Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Pages\Drivers\FoundationDriver;
use Daikazu\BladeWind\Pages\Drivers\TachyonsDriver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind3Driver;
use Daikazu\BladeWind\Pages\Drivers\Tailwind4Driver;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Tests\Support\UnoCssDriver;

it('merges the package configuration', function (): void {
    expect(config('bladewind.enabled'))->toBeTrue()
        ->and(config('bladewind.pages.enabled'))->toBeTrue()
        ->and(config('bladewind.cache_path'))->toBeNull();
});

it('registers the CSS framework drivers in detection order and selects by configuration', function (): void {
    $tagged = array_values(iterator_to_array(app()->tagged('bladewind.css-drivers'), false));

    expect(config('bladewind.framework'))->toBe('auto')
        ->and($tagged)->each->toBeInstanceOf(CssFrameworkDriver::class)
        ->and(array_map(static fn (CssFrameworkDriver $driver): string => $driver->name(), $tagged))->toBe(['tailwind4', 'tailwind3', 'tachyons', 'bootstrap5', 'bulma', 'foundation'])
        ->and(app(DriverSelector::class))->toBe(app(DriverSelector::class))
        ->and(app(DriverSelector::class)->select('@layer utilities{.a{x:y}}'))->toBeInstanceOf(Tailwind4Driver::class)
        ->and(app(DriverSelector::class)->select('*,:before{--tw-a:0}.a{x:y}'))->toBeInstanceOf(Tailwind3Driver::class)
        ->and(app(DriverSelector::class)->select('/*! TACHYONS v4.12.0 | http://tachyons.io */html{x:y}.a{x:y}'))->toBeInstanceOf(TachyonsDriver::class)
        ->and(app(DriverSelector::class)->select(':root{--bs-blue:#0d6efd}.btn{x:y}'))->toBeInstanceOf(Bootstrap5Driver::class)
        ->and(app(DriverSelector::class)->select(':root{--bulma-scheme-h:221}.button{x:y}'))->toBeInstanceOf(BulmaDriver::class)
        ->and(app(DriverSelector::class)->select('.reveal-overlay{x:y}.grid-x{x:y}'))->toBeInstanceOf(FoundationDriver::class)
        ->and(app(DriverSelector::class)->select('.a{x:y}'))->toBeNull();
});

it('puts the drivers bladewind.drivers names ahead of the built-in ones', function (): void {
    config()->set('bladewind.drivers', [UnoCssDriver::class]);
    app()->forgetInstance(DriverSelector::class);

    $selector = app(DriverSelector::class);
    $css = ':root{--un-a:0}.flex{display:flex}';

    expect($selector->select($css))->toBeInstanceOf(UnoCssDriver::class)
        ->and($selector->select($css)?->name())->toBe('unocss')
        ->and($selector->select($css)?->split($css)->root)->toBe(':root{--un-a:0}')
        ->and($selector->select($css)?->runtimeTokens())->toBe(['uno-runtime'])
        // The built-in drivers are still there, after it.
        ->and($selector->select('@layer utilities{.a{x:y}}'))->toBeInstanceOf(Tailwind4Driver::class)
        ->and($selector->expectations()[0])->toBe('rule with a class selector');

    config()->set('bladewind.framework', 'unocss');

    expect($selector->select('.a{x:y}'))->toBeInstanceOf(UnoCssDriver::class);
});

it('refuses a bladewind.drivers entry that is not a driver', function (): void {
    config()->set('bladewind.drivers', [stdClass::class]);
    app()->forgetInstance(DriverSelector::class);

    expect(fn () => app(DriverSelector::class))->toThrow(InvalidArgumentException::class, 'bladewind.drivers entry [stdClass]');
});

it('validates the configuration when it boots, so a bad drivers entry fails there rather than on every request', function (): void {
    config()->set('bladewind.drivers', [stdClass::class]);
    app()->forgetInstance(DriverSelector::class);

    expect(fn () => (new BladeWindServiceProvider(app()))->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'bladewind.drivers entry [stdClass]');
});

it('refuses an assets.path that is empty or leaves the public directory, when it boots', function (string $path): void {
    config()->set('bladewind.assets.path', $path);
    app()->forgetInstance(PageStyleStore::class);

    expect(fn () => (new BladeWindServiceProvider(app()))->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'bladewind.assets.path');
})->with(['', '/', '../outside', 'a/../../b']);

it('accepts the configuration it ships with', function (): void {
    (new BladeWindServiceProvider(app()))->validateConfiguration();

    expect(app(PageStyleStore::class)->directory())->toEndWith('/'.$this->assetsPath());
});

it('exposes a version constant', function (): void {
    expect(BladeWind::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});
