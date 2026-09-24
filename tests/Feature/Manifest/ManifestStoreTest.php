<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Discovery\SourceFile;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Illuminate\Filesystem\Filesystem;

it('defaults its root to the compiled views directory', function (): void {
    expect(app(ManifestStore::class)->root())->toBe($this->compiledPath.'/bladewind');
});

it('memoises get() per entry file keyed on its stat, refreshing on put() or an external rewrite', function (): void {
    $store = app(ManifestStore::class);
    $home = analyzedEntry('views/pages/home.blade.php');
    $store->put($home);

    $reader = new ManifestStore(new Filesystem, $store->root(), app(CompatHash::class));
    $first = $reader->get($home->path);

    expect($first)->toEqual($home)
        ->and($reader->get($home->path))->toBe($first);

    $updated = analyzedEntry('views/pages/home.blade.php');
    $store->put($updated);

    expect($store->get($home->path))->toBe($updated)
        ->and($store->get($home->path))->not->toBe($home);

    $path = $store->entryPath($home->path);
    $raw = json_decode((string) file_get_contents($path), true);
    $raw['name'] = 'pages.home.changed';
    file_put_contents($path, json_encode($raw));

    // Filesystem mtime resolution can be coarse (whole seconds); push it forward so the
    // [filemtime, filesize] key deterministically differs from what get() memoised.
    clearstatcache(true, $path);
    touch($path, filemtime($path) + 1);
    clearstatcache(true, $path);

    expect($store->get($home->path)?->name)->toBe('pages.home.changed');
});

it('writes, reads back, lists, and forgets entries', function (): void {
    $store = app(ManifestStore::class);
    $home = analyzedEntry('views/pages/home.blade.php');
    $about = analyzedEntry('views/pages/about.blade.php');

    $store->put($about);
    $store->put($home);

    expect($store->entryPath($home->path))->toBe($store->root().'/views/'.hash('xxh128', $home->path).'.json')
        ->and(file_exists($store->entryPath($home->path)))->toBeTrue()
        ->and($store->get($home->path))->toEqual($home)
        ->and(array_map(fn (ViewEntry $e) => $e->name, $store->all()))->toBe(['pages.about', 'pages.home']);

    $store->forget($home->path);

    expect($store->get($home->path))->toBeNull()
        ->and($store->all())->toHaveCount(1);
});

it('treats entries with a different compat hash or schema as absent', function (): void {
    $store = app(ManifestStore::class);
    $home = analyzedEntry('views/pages/home.blade.php');
    $store->put($home);

    $raw = json_decode(file_get_contents($store->entryPath($home->path)), true);
    $raw['compat_hash'] = 'stale';
    file_put_contents($store->entryPath($home->path), json_encode($raw));

    expect($store->get($home->path))->toBeNull()->and($store->all())->toBe([]);

    $raw['compat_hash'] = $home->compatHash;
    $raw['schema'] = 99;
    file_put_contents($store->entryPath($home->path), json_encode($raw));

    expect($store->get($home->path))->toBeNull();
});

it('writes atomically and leaves no temporary files behind', function (): void {
    $store = app(ManifestStore::class);
    $store->put(analyzedEntry('views/pages/home.blade.php'));

    expect(glob($store->root().'/views/*.tmp'))->toBe([]);
});

it('clears everything under its root', function (): void {
    $store = app(ManifestStore::class);
    $store->put(analyzedEntry('views/pages/home.blade.php'));

    expect($store->clear())->toBeTrue()
        ->and(is_dir($store->root()))->toBeFalse()
        ->and($store->clear())->toBeFalse();
});

it('prunes entries under the given roots that are not in the keep list', function (): void {
    $store = app(ManifestStore::class);
    $home = analyzedEntry('views/pages/home.blade.php');
    $about = analyzedEntry('views/pages/about.blade.php');
    $badge = analyzedEntry('ui/badge.blade.php');
    $store->put($home);
    $store->put($about);
    $store->put($badge);

    $deleted = $store->prune([$this->fixturePath('views')], [$home->path]);

    expect($deleted)->toBe([$about->path])
        ->and($store->get($about->path))->toBeNull()
        ->and($store->get($home->path))->not->toBeNull()
        ->and($store->get($badge->path))->not->toBeNull();
});

it('prunes an entry with a stale compat hash even though get() treats it as absent', function (): void {
    $store = app(ManifestStore::class);
    $simple = analyzedEntry('views/simple.blade.php');
    $store->put($simple);

    $raw = json_decode(file_get_contents($store->entryPath($simple->path)), true);
    $raw['compat_hash'] = 'stale';
    file_put_contents($store->entryPath($simple->path), json_encode($raw));

    expect($store->get($simple->path))->toBeNull();

    $deleted = $store->prune([$this->fixturePath('views')], []);

    expect($deleted)->toBe([$simple->path])
        ->and(file_exists($store->entryPath($simple->path)))->toBeFalse();
});

it('leaves an unreadable entry file in place and does not report it as deleted', function (): void {
    $store = app(ManifestStore::class);
    $store->put(analyzedEntry('views/pages/home.blade.php'));

    $malformed = $store->root().'/views/malformed.json';
    file_put_contents($malformed, 'not valid json');

    $deleted = $store->prune([$this->fixturePath('views')], []);

    expect(file_exists($malformed))->toBeTrue()
        ->and($deleted)->not->toContain($malformed);
});

it('prunes an entry whose source file no longer exists, wherever it lived', function (): void {
    $store = app(ManifestStore::class);
    $home = analyzedEntry('views/pages/home.blade.php');
    $store->put($home);

    // A compiled Livewire view: outside every configured root, and regenerated under a new name
    // after each edit, so the old entry would otherwise stay behind forever.
    $vanished = sys_get_temp_dir().'/bladewind-vanished-'.bin2hex(random_bytes(4)).'.blade.php';
    file_put_contents($vanished, '<div class="p-2"></div>');
    $entry = app(ViewAnalyzer::class)->analyze(
        new SourceFile($vanished, basename($vanished), 'vanished', ViewKind::View),
        (string) file_get_contents($vanished),
    );
    $store->put($entry);
    unlink($vanished);

    $deleted = $store->prune([$this->fixturePath('views')], [$home->path]);

    expect($deleted)->toBe([$vanished])
        ->and($store->get($home->path))->not->toBeNull()
        ->and($store->get($vanished))->toBeNull();
});
