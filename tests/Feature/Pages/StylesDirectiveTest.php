<?php

declare(strict_types=1);

use Daikazu\BladeWind\Pages\PageStyles;
use Daikazu\BladeWind\Pages\StylesDirective;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Support\Facades\Blade;

function markedStylesheetLink(): string
{
    return '<link rel="stylesheet" href="'.asset('build/assets/app-test.css').'" '.StylesDirective::MARKER.'>';
}

it('emits the full stylesheet marked for replacement', function (): void {
    expect(Blade::render('@bladewindStyles'))->toBe(markedStylesheetLink());
});

it('throws like @vite when a configured stylesheet is not in the Vite manifest', function (): void {
    // `@vite(['resources/css/absent.css'])` throws here; a typo in `bladewind.stylesheets` must not
    // turn into a page with no stylesheet at all.
    config()->set('bladewind.stylesheets', ['resources/css/absent.css']);
    app()->forgetInstance(StylesheetIndex::class);
    app()->forgetInstance(PageStyles::class);
    app()->forgetInstance(StylesDirective::class);

    expect(fn (): string => app(StylesDirective::class)->render())
        ->toThrow(ViteException::class, 'Unable to locate file in Vite manifest: resources/css/absent.css');
});

it('throws like @vite when the Vite manifest is missing', function (): void {
    app()->usePublicPath(sys_get_temp_dir().'/bladewind-nowhere-'.bin2hex(random_bytes(3)));
    app()->forgetInstance(StylesheetIndex::class);
    app()->forgetInstance(PageStyles::class);
    app()->forgetInstance(StylesDirective::class);

    expect(fn (): string => app(StylesDirective::class)->render())->toThrow(ViteManifestNotFoundException::class);
});

it('throws when a configured entry is a script rather than a stylesheet', function (): void {
    // A JS entry whose CSS is imported from JavaScript resolves through the manifest to a .js file;
    // linking that as a stylesheet is worse than failing.
    $files = new Filesystem;
    $scratch = sys_get_temp_dir().'/bladewind-script-'.bin2hex(random_bytes(3));
    $files->ensureDirectoryExists($scratch.'/build/assets');
    $files->put($scratch.'/build/assets/app-x.js', 'console.log(1)');
    $files->put($scratch.'/build/manifest.json', json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-x.js', 'src' => 'resources/js/app.js', 'isEntry' => true, 'css' => ['assets/app-y.css']],
    ], JSON_THROW_ON_ERROR));
    config()->set('bladewind.stylesheets', ['resources/js/app.js']);
    app()->usePublicPath($scratch);
    app()->forgetInstance(StylesheetIndex::class);
    app()->forgetInstance(PageStyles::class);
    app()->forgetInstance(StylesDirective::class);

    try {
        expect(fn (): string => app(StylesDirective::class)->render())
            ->toThrow(ViteException::class, 'resources/js/app.js');
    } finally {
        $files->deleteDirectory($scratch);
    }
});

it('emits the comment placeholder only when no stylesheet is configured at all', function (): void {
    config()->set('bladewind.stylesheets', []);
    app()->forgetInstance(StylesheetIndex::class);
    app()->forgetInstance(PageStyles::class);
    app()->forgetInstance(StylesDirective::class);

    expect(Blade::render('@bladewindStyles'))->toBe(StylesDirective::PLACEHOLDER);
});

it('emits the same marked links when bladewind is disabled, which is then what the page keeps', function (): void {
    // With the package disabled no middleware is registered, so nothing replaces these links; they
    // are already the full stylesheet, which is exactly what `@vite` would have emitted.
    config()->set('bladewind.enabled', false);

    expect(Blade::render('@bladewindStyles'))->toBe(markedStylesheetLink());
});

it('carries the Vite CSP nonce on the marked links', function (): void {
    app(Vite::class)->useCspNonce('n0nce-value');

    expect(Blade::render('@bladewindStyles'))->toBe(
        '<link rel="stylesheet" href="'.asset('build/assets/app-test.css').'" '.StylesDirective::MARKER.' nonce="n0nce-value">',
    );
});
