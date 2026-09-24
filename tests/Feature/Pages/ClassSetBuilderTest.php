<?php

declare(strict_types=1);

use Daikazu\BladeWind\Analysis\IndexRow;
use Daikazu\BladeWind\Analysis\ViewAnalyzer;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Integration\CompatHash;
use Daikazu\BladeWind\Integration\PathFilter;
use Daikazu\BladeWind\Manifest\ClassesBlock;
use Daikazu\BladeWind\Manifest\Dependency;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Fingerprint;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Manifest\ViewEntry;
use Daikazu\BladeWind\Pages\ClassSetBuilder;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * A builder over the given manifest store and filesystem, wired as the service provider wires the
 * real one: the analyser and the logger resolved from the container on first use, never before.
 */
function classSetBuilderWith(ManifestStore $entries, Filesystem $files): ClassSetBuilder
{
    return new ClassSetBuilder(
        $entries,
        app(ViewLocator::class),
        static fn (): ViewAnalyzer => app(ViewAnalyzer::class),
        $files,
        static fn (): LoggerInterface => app(LoggerInterface::class),
    );
}

it('unions HTML classes with the inventories of rendered views and their static dependency closure', function (): void {
    foreach (['views/pages/compress.blade.php', 'views/components/tile.blade.php', 'views/livewire/ticker.blade.php'] as $relative) {
        app(ManifestStore::class)->put(analyzeFixture($relative));
    }

    $html = '<div class="mt-2 flex only-in-html"></div>';
    $set = app(ClassSetBuilder::class)->build($html, [
        $this->fixturePath('views/pages/compress.blade.php'),   // depends on components/tile.blade.php
        $this->fixturePath('views/livewire/ticker.blade.php'),  // wire:loading.class="opacity-50"
        '/vendor/somewhere/pagination.blade.php',               // no entry
    ]);

    expect($set->tokens)->toContain('only-in-html', 'mt-2', 'flex', 'hover:bg-gray-100', 'opacity-50', 'ring-2')
        ->and($set->tokens)->toContain(...analyzeFixture('views/components/tile.blade.php')->classes->groups[0]->tokens)
        ->and($set->tokens)->toBe(array_values(array_unique($set->tokens)))
        ->and($set->unanalysed)->toBe(1)
        ->and($set->analysed)->toBe(3)
        ->and($set->hash('sheet'))->toHaveLength(32)
        ->and($set->hash('sheet'))->not->toBe($set->hash('other'));
});

it('yields only HTML tokens when nothing rendered has an entry', function (): void {
    $set = app(ClassSetBuilder::class)->build('<p class="b a"></p>', ['/nowhere.blade.php']);

    expect($set->tokens)->toBe(['a', 'b'])->and($set->unanalysed)->toBe(1)->and($set->analysed)->toBe(0);
});

it('sorts extra pseudo-tokens into the set so the hash follows them', function (): void {
    $builder = app(ClassSetBuilder::class);
    $html = '<p class="b a"></p>';

    $plain = $builder->build($html, []);
    $withVariables = $builder->build($html, [], ['--color-brand', '--color-brand']);

    // The custom properties a page reads are not classes, but they decide what its file carries, so
    // they belong to its identity: same classes and a different variable is a different page file.
    expect($withVariables->tokens)->toBe(['--color-brand', 'a', 'b'])
        ->and($plain->tokens)->toBe(['a', 'b'])
        ->and($withVariables->hash('sheet'))->not->toBe($plain->hash('sheet'))
        ->and($withVariables->analysed)->toBe(0)
        ->and($withVariables->unanalysed)->toBe(0);
});

it('terminates on a dependency cycle between mutually-including views, counting each entry once', function (): void {
    foreach (['views/cycle/a.blade.php', 'views/cycle/b.blade.php'] as $relative) {
        app(ManifestStore::class)->put(analyzeFixture($relative));
    }

    $set = app(ClassSetBuilder::class)->build('<p></p>', [$this->fixturePath('views/cycle/a.blade.php')]);

    expect($set->analysed)->toBe(2)->and($set->unanalysed)->toBe(0);
});

it('returns a purely numeric token in an entry\'s index as a string', function (): void {
    $path = $this->fixturePath('views/pages/about.blade.php');
    $entry = new ViewEntry(
        path: $path,
        relativePath: 'pages/about.blade.php',
        name: 'pages.about',
        kind: ViewKind::View,
        fingerprint: Fingerprint::fromSource((string) file_get_contents($path), $path),
        compatHash: 'hash',
        dependencies: [],
        classes: new ClassesBlock([], [], [], [], ['123' => new IndexRow(1, 0, 0, 0, false, false)]),
        diagnostics: [],
    );
    app(ManifestStore::class)->put($entry);

    $set = app(ClassSetBuilder::class)->build('<p></p>', [$path]);

    expect($set->tokens)->toBe(['123']);
});

it('analyses a rendered view with no entry on demand, and stores what it found', function (): void {
    $path = $this->fixturePath('views/livewire/ticker.blade.php');
    $entries = app(ManifestStore::class);

    expect($entries->get($path))->toBeNull();

    $set = app(ClassSetBuilder::class)->build('<p class="only-in-html"></p>', [$path]);

    // opacity-50 lives in a wire:loading.class and never in a class attribute, so it can only have
    // arrived through an analysis — one that happened during this build, the manifest being empty.
    expect($set->tokens)->toContain('opacity-50', 'only-in-html')
        ->and($set->analysed)->toBe(1)
        ->and($set->unanalysed)->toBe(0)
        ->and($entries->get($path))->not->toBeNull();
});

it('leaves a rendered path outside the configured roots unanalysed', function (): void {
    // The locator describes nothing here (it is neither under bladewind.paths nor one of Livewire's
    // compiled views), so there is no entry to write and BW6003 still counts the path.
    $outside = $this->compiledPath.'/outside.blade.php';
    file_put_contents($outside, '<div class="never-analysed"></div>');

    $set = app(ClassSetBuilder::class)->build('<p class="a"></p>', [$outside]);

    expect($set->tokens)->toBe(['a'])
        ->and($set->unanalysed)->toBe(1)
        ->and($set->analysed)->toBe(0)
        ->and(app(ManifestStore::class)->get($outside))->toBeNull();
});

it('replaces an entry written under another compatibility hash', function (): void {
    $path = $this->fixturePath('views/livewire/ticker.blade.php');
    $entries = app(ManifestStore::class);

    // A package upgrade moves the compatibility hash, so every entry on disk is rejected at once
    // while the compiled views stay warm and nothing recompiles to rewrite them.
    $data = json_decode(analyzeFixture('views/livewire/ticker.blade.php')->toJson(), true);
    $data['compat_hash'] = 'stale';
    $entries->writeAtomically($entries->entryPath($path), (string) json_encode($data));

    expect($entries->get($path))->toBeNull();

    $set = app(ClassSetBuilder::class)->build('<p></p>', [$path]);

    expect($set->tokens)->toContain('opacity-50')
        ->and($set->analysed)->toBe(1)
        ->and($set->unanalysed)->toBe(0)
        ->and($entries->get($path)?->compatHash)->toBe(app(CompatHash::class)->current());
});

it('keeps using an on-demand entry whose only failure was storing it, and stores it once the store allows', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'BW1008: on-demand analysis of')
            && str_contains($message, 'failed: read-only manifest')
            && $context['message'] === 'read-only manifest',
    );

    $path = $this->fixturePath('views/livewire/ticker.blade.php');
    $filesystem = new class extends Filesystem
    {
        public bool $writable = false;

        public int $puts = 0;

        public function put($path, $contents, $lock = false)
        {
            $this->puts++;

            if (! $this->writable) {
                throw new RuntimeException('read-only manifest');
            }

            return parent::put($path, $contents, $lock);
        }
    };
    $store = new ManifestStore($filesystem, app(ManifestStore::class)->root(), app(CompatHash::class));
    $builder = classSetBuilderWith($store, new Filesystem);

    $first = $builder->build('<p class="only-in-html"></p>', [$path]);
    $second = $builder->build('<p class="only-in-html"></p>', [$path]);

    // The entry was computed and correct — only saving it failed — so every request keeps the
    // runtime-only classes it found (opacity-50 lives in a wire:loading.class and nowhere else),
    // from one Forte parse: the second request reuses the entry and only retries the write.
    expect($first->tokens)->toContain('opacity-50', 'only-in-html')
        ->and($first->analysed)->toBe(1)
        ->and($second->tokens)->toContain('opacity-50')
        ->and($second->analysed)->toBe(1)
        ->and($second->unanalysed)->toBe(0)
        ->and($filesystem->puts)->toBe(2)
        ->and($store->get($path))->toBeNull();

    // A directory that becomes writable (a deploy fixing permissions) gets the entry on the next
    // request without another parse.
    $filesystem->writable = true;
    $third = $builder->build('<p></p>', [$path]);

    expect($third->tokens)->toContain('opacity-50')
        ->and($store->get($path))->not->toBeNull()
        ->and($filesystem->puts)->toBe(3);
});

it('counts a dependency whose resolved file is gone as unanalysed, rather than serving the deleted file\'s entry', function (): void {
    // A child component renamed or moved: the parent's stored dependency still names the old file,
    // Blade does not recompile the parent for a resolution change, and the old entry would go on
    // standing in for a child that now lives elsewhere.
    $parent = $this->fixturePath('views/pages/about.blade.php');
    $gone = $this->fixturePath('views/components/gone.blade.php');
    $entry = new ViewEntry(
        path: $parent,
        relativePath: 'pages/about.blade.php',
        name: 'pages.about',
        kind: ViewKind::View,
        fingerprint: Fingerprint::fromSource((string) file_get_contents($parent), $parent),
        compatHash: app(CompatHash::class)->current(),
        dependencies: [new Dependency(DependencyType::Component, 'gone', Resolution::Static, $gone, null, 1, 1)],
        classes: new ClassesBlock([], [], [], [], ['p-1' => new IndexRow(1, 0, 0, 0, false, false)]),
        diagnostics: [],
    );
    app(ManifestStore::class)->put($entry);

    $set = app(ClassSetBuilder::class)->build('<p></p>', [$parent]);

    expect($set->tokens)->toBe(['p-1'])
        ->and($set->analysed)->toBe(1)
        ->and($set->unanalysed)->toBe(1)
        ->and($set->unanalysedPaths)->toBe([$gone]);
});

it('treats an entry whose source has changed since as absent, and analyses the current file', function (): void {
    // The compile observer writes an entry when Blade compiles a view, but Blade can compile a view
    // without the observer seeing the file's own source (an earlier precompiler rewrote it, the
    // entry was shipped from another checkout): an entry must only be trusted while its
    // fingerprint still matches the file it describes.
    $root = (string) realpath(sys_get_temp_dir()).'/bladewind-stale-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/pages');
    $path = $root.'/pages/stale.blade.php';
    config()->set('bladewind.paths', [...config('bladewind.paths'), $root]);

    foreach ([PathFilter::class, ViewLocator::class, CompatHash::class, ViewAnalyzer::class, ManifestStore::class, ClassSetBuilder::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    try {
        $files->put($path, '<div class="p-1"></div>');
        $entries = app(ManifestStore::class);
        $entries->put(app(ViewAnalyzer::class)->analyze(app(ViewLocator::class)->describe($path), (string) file_get_contents($path)));

        expect($entries->get($path)?->classes->index)->toHaveKey('p-1');

        $files->put($path, '@if($open)<div class="p-2"></div>@endif');
        touch($path, time() + 5);

        $set = app(ClassSetBuilder::class)->build('<p></p>', [$path]);

        expect($set->tokens)->toContain('p-2')->not->toContain('p-1')
            ->and($set->analysed)->toBe(1)
            ->and($entries->get($path)?->classes->index)->toHaveKey('p-2');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('counts a view whose source cannot be read as unanalysed, attempting it once', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'BW1008: on-demand analysis of')
            && str_contains($message, 'failed: gone from disk'),
    );

    $path = $this->fixturePath('views/livewire/ticker.blade.php');
    $unreadable = new class extends Filesystem
    {
        public function get($path, $lock = false)
        {
            throw new FileNotFoundException('gone from disk');
        }
    };
    $builder = classSetBuilderWith(app(ManifestStore::class), $unreadable);

    $first = $builder->build('<p class="a"></p>', [$path]);
    $second = $builder->build('<p class="a"></p>', [$path]);

    // The single log line is what says the second build did not try again.
    expect($first->tokens)->toBe(['a'])
        ->and($first->analysed)->toBe(0)
        ->and($first->unanalysed)->toBe(1)
        ->and($second->unanalysed)->toBe(1);
});

it('builds no analyser at all when every rendered view already has an entry', function (): void {
    $path = $this->fixturePath('views/components/tile.blade.php');
    app(ManifestStore::class)->put(analyzeFixture('views/components/tile.blade.php'));

    // Resolving ViewAnalyzer means constructing the Forte parser, the class analyser
    // and the compatibility hash, on every request that renders a page — for nothing, when the
    // manifest is warm, which is every request but the first after a deploy.
    $builder = new ClassSetBuilder(
        app(ManifestStore::class),
        app(ViewLocator::class),
        static fn (): ViewAnalyzer => throw new RuntimeException('the analyser was constructed'),
        new Filesystem,
        static fn (): LoggerInterface => app(LoggerInterface::class),
    );

    expect($builder->build('<p class="a"></p>', [$path])->analysed)->toBe(1);
});
