<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Graph\GraphNode;
use Daikazu\BladeWind\Graph\GraphStore;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Illuminate\Filesystem\Filesystem;

it('builds, caches, and invalidates the graph by entry fingerprint', function (): void {
    $store = app(ManifestStore::class);
    $graphs = app(GraphStore::class);
    $store->put(analyzedEntry('views/pages/home.blade.php'));

    expect($graphs->get())->toBeNull();

    $first = $graphs->graph();
    $data = json_decode((string) file_get_contents($graphs->path()), true);

    expect(file_exists($graphs->path()))->toBeTrue()
        ->and(array_keys($data))->toBe(['schema', 'generated_at', 'compat_hash', 'entries_fingerprint', 'edges', 'nodes', 'cycles'])
        ->and($data['entries_fingerprint'])->toBe($graphs->fingerprint())
        ->and($graphs->get()?->toArray())->toBe($first->toArray());

    $store->put(analyzedEntry('views/pages/about.blade.php'));

    expect($graphs->get())->toBeNull()
        ->and(count($graphs->graph()->nodes()))->toBeGreaterThan(count($first->nodes()));
});

it('detects a same-second content change to an entry through a content hash rather than mtime', function (): void {
    $store = app(ManifestStore::class);
    $graphs = app(GraphStore::class);
    $store->put(analyzedEntry('views/pages/home.blade.php'));
    $graphs->graph();

    $entryFile = $store->entryPath($this->fixturePath('views/pages/home.blade.php'));
    $before = (string) file_get_contents($entryFile);
    $mtime = filemtime($entryFile);

    // Same byte length and mtime, different content: an mtime+size fingerprint would miss this
    // entirely, but a content hash must not.
    $mutated = $before;
    $mutated[2] = $mutated[2] === 'a' ? 'b' : 'a';
    file_put_contents($entryFile, $mutated);
    touch($entryFile, $mtime);
    clearstatcache(true, $entryFile);

    expect(filemtime($entryFile))->toBe($mtime)
        ->and(strlen($mutated))->toBe(strlen($before))
        ->and($graphs->get())->toBeNull();
});

it('rejects a cached graph written under another compat hash', function (): void {
    $graphs = app(GraphStore::class);
    app(ManifestStore::class)->put(analyzedEntry('views/simple.blade.php'));
    $graphs->refresh();

    $data = json_decode((string) file_get_contents($graphs->path()), true);
    $data['compat_hash'] = 'stale';
    file_put_contents($graphs->path(), json_encode($data));

    expect($graphs->get())->toBeNull();
});

it('leaves Livewire\'s compiled component views out of the graph', function (): void {
    $store = app(ManifestStore::class);
    $compiled = (string) config('view.compiled').'/livewire/views/7de71992.blade.php';
    (new Filesystem)->ensureDirectoryExists(dirname($compiled));
    (new Filesystem)->put($compiled, '<div class="flex items-center gap-2 rounded-lg border p-4">zap</div>');

    $entry = app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($compiled), (string) file_get_contents($compiled));
    $store->put(analyzedEntry('views/pages/home.blade.php'));
    $store->put($entry);

    expect($entry->kind)->toBe(ViewKind::LivewireCompiled);

    // The entry exists so a page's class set can find the component's inventory; it is a derived
    // copy of a source view, so counting it in the graph would double-count the same markup under
    // a cache-hash name nobody wrote.
    $names = array_map(static fn (GraphNode $node): ?string => $node->name, app(GraphStore::class)->refresh()->nodes());

    expect($names)->not->toContain($entry->name)
        ->and($store->get($compiled))->not->toBeNull();
});
