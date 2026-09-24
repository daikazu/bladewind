<?php

declare(strict_types=1);

use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Pages\PageStyleStore;

it('removes the manifest directory and says how entries come back', function (): void {
    $this->artisan('bladewind:analyze')->assertSuccessful();
    $root = app(ManifestStore::class)->root();
    expect(is_dir($root))->toBeTrue();

    // The hint is unconditional, and says why: without it a warm compiled view cache means no view
    // recompiles, no entry is written, and page styles fall back to HTML tokens alone (BW6003).
    $this->artisan('bladewind:clear')
        ->expectsOutputToContain('Removed')
        ->expectsOutputToContain('Entries come back as views render')
        ->assertSuccessful();

    expect(is_dir($root))->toBeFalse();
});

it('removes page and root stylesheets', function (): void {
    $store = app(PageStyleStore::class);
    $store->write($store->rootFile('sheet'), "root{1:1}\n");
    $store->write($store->pageFile('sheet', 'set'), "page{1:1}\n");

    $this->artisan('bladewind:clear')
        ->expectsOutputToContain('Removed 2 page stylesheets')
        ->assertSuccessful();

    expect(glob($store->directory().'/bw-root-*.css'))->toBe([])
        ->and(glob($store->directory().'/bw-page-*.css'))->toBe([]);
});

it('succeeds when there is nothing to clear', function (): void {
    $this->artisan('bladewind:clear')
        ->expectsOutputToContain('Nothing to clear')
        ->assertSuccessful();
});

it('is wiped by view:clear as well', function (): void {
    $this->artisan('bladewind:analyze')->assertSuccessful();
    $root = app(ManifestStore::class)->root();

    $this->artisan('view:clear')->assertSuccessful();

    expect(is_dir($root))->toBeFalse();
});
