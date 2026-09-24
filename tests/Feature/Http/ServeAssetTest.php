<?php

declare(strict_types=1);

use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Pages\NavigateScript;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
});

it('serves the full stylesheet, uncached, for a generated file that is not on this server', function (): void {
    // Another node wrote the file, or it was pruned after an HTML cache linked it: the browser
    // asked for a stylesheet, and the full one is always right. no-store, so the miss never sticks.
    $response = $this->get('/'.$this->assetsPath().'/bw-page-0123456789ab-0123456789ab.css')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/css')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toBe((string) file_get_contents($this->fixturePath('public/build/assets/app-test.css')));

    $this->get('/'.$this->assetsPath().'/bw-root-0123456789ab.css')->assertOk();
});

it('serves a generated file that does exist, as the web server would, cacheable for good', function (): void {
    $store = app(PageStyleStore::class);
    (new Filesystem)->ensureDirectoryExists($store->directory());
    $store->write('bw-page-0123456789ab-0123456789ab.css', '.a{x:y}');

    $response = $this->get('/'.$this->assetsPath().'/bw-page-0123456789ab-0123456789ab.css')->assertOk();

    expect($response->getContent())->toBe('.a{x:y}')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');
});

it('serves the navigate script by its versioned name, cacheable for good', function (): void {
    $response = $this->get('/'.$this->assetsPath().'/'.NavigateScript::file())->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/javascript')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->getContent())->toBe(NavigateScript::SOURCE);
});

it('answers only the names it generates', function (): void {
    $this->get('/'.$this->assetsPath().'/other.css')->assertNotFound();
    $this->get('/'.$this->assetsPath().'/bw-page-not-a-hash.css')->assertNotFound();
    $this->get('/'.$this->assetsPath().'/../../.env')->assertNotFound();
});

it('registers no route when page styles are off', function (): void {
    config()->set('bladewind.pages.enabled', false);
    app()->forgetInstance(Router::class);

    expect((new BladeWindServiceProvider(app()))->registerAssetRoute())->toBeFalse();
});
