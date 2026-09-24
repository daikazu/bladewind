<?php

declare(strict_types=1);

use Daikazu\BladeWind\Discovery\SourceFile;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Discovery\ViewLocator;
use Daikazu\BladeWind\Integration\PathFilter;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/bw-locate-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    foreach ([
        'resources/views/pages/home.blade.php',
        'resources/views/components/card.blade.php',
        'resources/views/components/forms/input.blade.php',
        'resources/views/vendor/mail/html/layout.blade.php',
        'resources/views/notes.txt',
        'resources/views/plain.php',
        'packages/ui/badge.blade.php',
    ] as $relative) {
        $files->ensureDirectoryExists(dirname($this->base.'/'.$relative));
        $files->put($this->base.'/'.$relative, '');
    }

    $this->livewireViews = $this->base.'/storage/framework/views/livewire/views';
    $files->ensureDirectoryExists($this->livewireViews);
    $files->put($this->livewireViews.'/a1b2c3d4.blade.php', '<div class="flex"></div>');

    $this->locator = new ViewLocator(
        $files,
        $this->base,
        new PathFilter([$this->base.'/resources/views', $this->base.'/packages/ui']),
        fn (): array => [$this->base.'/packages/ui'],
        fn (): ?string => $this->livewireViews,
    );
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->base);
});

it('locates blade files sorted by relative path, including published vendor views, skipping non-blade files', function (): void {
    $files = $this->locator->locate();

    expect(array_map(fn (SourceFile $f): string => $f->relativePath, $files))->toBe([
        'packages/ui/badge.blade.php',
        'resources/views/components/card.blade.php',
        'resources/views/components/forms/input.blade.php',
        'resources/views/pages/home.blade.php',
        'resources/views/vendor/mail/html/layout.blade.php',
    ]);
});

it('derives logical names and kinds', function (): void {
    $byName = [];
    foreach ($this->locator->locate() as $file) {
        $byName[$file->name] = $file->kind;
    }

    expect($byName)->toBe([
        'badge' => ViewKind::Component,
        'components.card' => ViewKind::Component,
        'components.forms.input' => ViewKind::Component,
        'pages.home' => ViewKind::View,
        'vendor.mail.html.layout' => ViewKind::View,
    ]);
});

it('describes a single path inside a root and rejects paths outside', function (): void {
    $file = $this->locator->describe($this->base.'/resources/views/pages/home.blade.php');

    expect($file)->not->toBeNull()
        ->and($file?->name)->toBe('pages.home')
        ->and($file?->relativePath)->toBe('resources/views/pages/home.blade.php')
        ->and($this->locator->describe($this->base.'/resources/views/notes.txt'))->toBeNull()
        ->and($this->locator->describe('/nowhere/x.blade.php'))->toBeNull();
});

it('keeps the path it was asked about when the root is reached through a symlink', function (): void {
    // Blade reports the finder's path (through the symlink) to the compile observer and to the view
    // composer, so that is the path every entry has to be keyed by; the real path is only for
    // deciding which root the file is under.
    $base = (string) realpath(sys_get_temp_dir()).'/bw-symlink-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->ensureDirectoryExists($base.'/real/views/pages');
    $files->ensureDirectoryExists($base.'/real/views/components');
    $files->put($base.'/real/views/pages/a.blade.php', '');
    $files->put($base.'/real/views/components/b.blade.php', '');
    symlink($base.'/real/views', $base.'/link');

    try {
        $locator = new ViewLocator($files, $base, new PathFilter([$base.'/link']), fn (): array => [], fn (): ?string => null);
        $page = $locator->describe($base.'/link/pages/a.blade.php');
        $component = $locator->describe($base.'/link/components/b.blade.php');

        expect($page?->path)->toBe($base.'/link/pages/a.blade.php')
            ->and($page?->name)->toBe('pages.a')
            ->and($page?->kind)->toBe(ViewKind::View)
            ->and($page?->relativePath)->toBe('real/views/pages/a.blade.php')
            ->and($component?->path)->toBe($base.'/link/components/b.blade.php')
            ->and($component?->name)->toBe('components.b')
            ->and($component?->kind)->toBe(ViewKind::Component);
    } finally {
        $files->deleteDirectory($base);
    }
});

it('describes a compiled Livewire view, which no root contains', function (): void {
    $file = $this->locator->describe($this->livewireViews.'/a1b2c3d4.blade.php');

    expect($file)->not->toBeNull()
        ->and($file?->kind)->toBe(ViewKind::LivewireCompiled)
        ->and($file?->name)->toBe('livewire-compiled.a1b2c3d4')
        ->and($file?->relativePath)->toBe('a1b2c3d4.blade.php')
        // The path Livewire wrote, not its realpath: that is the string `app('view')->file()` gets,
        // so it is what the compiler reports and what a view composer reads back.
        ->and($file?->path)->toBe($this->livewireViews.'/a1b2c3d4.blade.php')
        // Only that directory: the rest of the compiled view cache stays out.
        ->and($this->locator->describe($this->base.'/storage/framework/views/livewire/views'))->toBeNull()
        // locate() still walks the configured roots only.
        ->and(array_map(fn (SourceFile $f): string => $f->name, $this->locator->locate()))->not->toContain('livewire-compiled.a1b2c3d4');
});

it('describes no compiled Livewire view when the application has no compiled view path', function (): void {
    $locator = new ViewLocator(
        new Filesystem,
        $this->base,
        new PathFilter([$this->base.'/resources/views']),
        fn (): array => [],
        fn (): ?string => null,
    );

    expect($locator->describe($this->livewireViews.'/a1b2c3d4.blade.php'))->toBeNull();
});

it('describes a published view under a root\'s vendor directory, matching locate()', function (): void {
    $file = $this->locator->describe($this->base.'/resources/views/vendor/mail/html/layout.blade.php');

    expect($file?->name)->toBe('vendor.mail.html.layout')
        ->and($file?->kind)->toBe(ViewKind::View);
});

it('locates only the given roots when some are passed', function (): void {
    $files = $this->locator->locate([$this->base.'/packages/ui']);

    expect($files)->toHaveCount(1)
        ->and($files[0]->name)->toBe('badge');
});
