<?php

declare(strict_types=1);

use App\Livewire\Ticker;
use Daikazu\BladeWind\BladeWindServiceProvider;
use Daikazu\BladeWind\Http\InjectPageStyles;
use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Pages\ClassSetBuilder;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Pages\HtmlClassScanner;
use Daikazu\BladeWind\Pages\PageCssBuilder;
use Daikazu\BladeWind\Pages\PageLinks;
use Daikazu\BladeWind\Pages\PageStyles;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Pages\StylesDirective;
use Daikazu\BladeWind\Pages\SupportCssBuilder;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Psr\Log\LoggerInterface;

beforeEach(function (): void {
    // Livewire signs its snapshots with the application key, which Testbench leaves empty.
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.debug', true);
    Route::get('/shaken', fn () => view('pages.shaken'));
    Route::get('/json', fn () => response()->json(['ok' => true]));
});

it('replaces the marked links with root and page links and writes both files once', function (): void {
    $response = $this->get('/shaken')->assertOk();
    $html = $response->getContent();
    $store = app(PageStyleStore::class);

    expect($html)->not->toContain(StylesDirective::MARKER)
        ->and($html)->not->toContain('app-test.css')
        ->and($html)->toMatch('~<link rel="stylesheet" href="[^"]*/'.$this->assetsPath().'/bw-root-[0-9a-f]{12}\.css"><link rel="stylesheet" href="[^"]*/'.$this->assetsPath().'/bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css" data-bladewind-nav="[0-9a-f]{6}"><script src="[^"]*/'.$this->assetsPath().'/bw-navigate-[0-9a-f]{12}\.js" data-bladewind-navigate defer></script>~')
        // The byte counts are only stat'ed while app.debug is on, which it is here.
        ->and($response->headers->get('X-BladeWind-Styles'))->toMatch('~^page=[0-9a-f]{12} framework=tailwind4 tokens=\d+ analysed=\d+ unanalysed=\d+ support=[1-9]\d* delivery=link root=[1-9]\d* page=[1-9]\d*$~');

    preg_match('~bw-root-[0-9a-f]{12}\.css~', (string) $html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', (string) $html, $page);
    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);

    // Nothing outside this fixture's utilities layer reads a variable, so the root keeps the theme
    // layer's cascade position and none of its declarations; the page carries the two it uses.
    expect($rootCss)->toContain('@layer theme;')->toContain('@layer utilities;')
        ->not->toContain('.flex{display:flex}')->not->toContain('--spacing')
        ->and($pageCss)->toStartWith('@layer theme{:root{--spacing:.25rem;--radius-lg:.5rem}}@layer utilities{')
        ->and($pageCss)->toContain('.flex{display:flex}')->toContain('.rounded-lg{')->toContain('.p-4,.px-4{')
        ->and($pageCss)->toContain('.block{display:block}')            // from the tile component (rendered through the dynamic component)
        ->and($pageCss)->not->toContain('.dark\:bg-black')              // in the stylesheet, used nowhere
        ->and($pageCss)->not->toContain('.sm\:gap-4');

    clearstatcache(true, $store->directory().'/'.$page[0]);
    $mtime = filemtime($store->directory().'/'.$page[0]);
    $again = $this->get('/shaken')->assertOk();

    clearstatcache(true, $store->directory().'/'.$page[0]);

    expect($again->getContent())->toContain($page[0])
        ->and(filemtime($store->directory().'/'.$page[0]))->toBe($mtime);
});

it('includes runtime classes the analyser recorded for rendered views', function (): void {
    $html = (string) $this->get('/shaken')->assertOk()->getContent();
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $html, $page);
    $pageCss = (string) file_get_contents(app(PageStyleStore::class)->directory().'/'.$page[0]);

    // ticker.blade.php carries wire:loading.class="opacity-50": the token is never in a class
    // attribute, so it reaches the page stylesheet through the view's analysed inventory alone.
    expect($html)->toContain('wire:loading.class="opacity-50"')
        ->and($pageCss)->toContain('.opacity-50{');
});

it('analyses rendered views on demand when their entries are gone but the compiled views are warm', function (): void {
    $warm = $this->get('/shaken')->assertOk()->headers->get('X-BladeWind-Styles');

    // What a package upgrade looks like from the request path: the compatibility hash moves, every
    // entry on disk is rejected at once, and Blade recompiles nothing because the compiled views are
    // still warm, so the compile observer never runs and never rewrites one. Deleting the entries
    // is that state without the version bump. Guards against every page getting only the classes
    // its HTML happened to wear until someone runs `view:clear`.
    (new Filesystem)->deleteDirectory(app(ManifestStore::class)->root());
    app(PageStyleStore::class)->clear();

    $response = $this->get('/shaken')->assertOk();
    $html = (string) $response->getContent();
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $html, $page);
    $pageCss = (string) file_get_contents(app(PageStyleStore::class)->directory().'/'.$page[0]);

    // opacity-50 is only ever in ticker.blade.php's wire:loading.class, so it reached the file
    // through an analysis; and the header is identical to the warm one, down to the set hash and the
    // byte counts, so the same views were analysed and the same page file came out of it.
    expect($html)->toContain('wire:loading.class="opacity-50"')
        ->and($pageCss)->toContain('.opacity-50{')
        ->and($response->headers->get('X-BladeWind-Styles'))->toBe($warm);
});

it('includes a Livewire single-file component\'s inventory, read from its compiled view', function (): void {
    // Livewire 4's default component format renders through the Blade file its compiler writes
    // under config('view.compiled')/livewire/views, so that path — not the ⚡ source — is what the
    // view composer records and what the class set must find an entry for.
    app('livewire.finder')->addLocation(viewPath: $this->fixturePath('views/components'));
    Route::get('/sfc', fn () => view('pages.sfc'));

    $html = (string) $this->get('/sfc')->assertOk()->getContent();
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $html, $page);

    expect($html)->toContain('zap 0')
        ->and($page)->not->toBeEmpty();

    $pageCss = (string) file_get_contents(app(PageStyleStore::class)->directory().'/'.$page[0]);

    // The token only exists in the component's wire:loading.class, never in a class attribute, so
    // it can only have arrived through the compiled view's analysed inventory.
    expect(HtmlClassScanner::tokens($html))->not->toContain('hover:bg-gray-100')
        ->and($html)->toContain('wire:loading.class="hover:bg-gray-100"')
        ->and($pageCss)->toContain('.hover\:bg-gray-100:hover{')
        ->and($pageCss)->toContain('.flex{display:flex}');

    // The entry lives outside bladewind.paths, so `bladewind:analyze`'s prune never sees it.
    $entries = app(ManifestStore::class);
    $compiled = collect(app('files')->files(rtrim((string) config('view.compiled'), '/').'/livewire/views'))
        ->map(static fn (SplFileInfo $file): string => $file->getPathname())
        ->filter(static fn (string $path): bool => str_ends_with($path, '.blade.php'))
        ->values();

    expect($compiled)->toHaveCount(1)
        ->and($entries->get($compiled[0]))->not->toBeNull();

    $entries->prune([$this->fixturePath('views'), $this->fixturePath('ui')], []);

    expect($entries->get($compiled[0]))->not->toBeNull();
});

it('leaves non-HTML responses and responses without the placeholder alone', function (): void {
    $this->get('/json')->assertOk()->assertExactJson(['ok' => true])->assertHeaderMissing('X-BladeWind-Styles');
});

it('describes itself in a header only while app.debug is on', function (): void {
    config()->set('app.debug', false);

    $response = $this->get('/shaken')->assertOk()->assertHeaderMissing('X-BladeWind-Styles');

    expect($response->getContent())->toContain('bw-page-');
});

it('replaces every marked run and leaves a literal comment in the body alone', function (): void {
    Route::get('/twice', fn () => response(Blade::render(
        '<!doctype html><html><head>@bladewindStyles @bladewindStyles</head><body class="flex">'.StylesDirective::PLACEHOLDER.'</body></html>',
    )));

    $html = (string) $this->get('/twice')->assertOk()->getContent();

    // Two directives, two runs, so this page's links land twice; a run left unreplaced would
    // otherwise be the whole stylesheet again. The comment in the body is content, not an
    // injection point: the marker is what the middleware acts on when the page carries one.
    expect(substr_count($html, 'bw-root-'))->toBe(2)
        ->and(substr_count($html, 'bw-page-'))->toBe(2)
        ->and(substr_count($html, StylesDirective::PLACEHOLDER))->toBe(1)
        ->and($html)->toContain('<body class="flex">'.StylesDirective::PLACEHOLDER);
});

it('replaces the comment placeholder when the directive could link nothing itself', function (): void {
    Route::get('/comment', fn () => response(Blade::render(
        '<!doctype html><html><head>'.StylesDirective::PLACEHOLDER.'</head><body class="flex"></body></html>',
    )));

    $html = (string) $this->get('/comment')->assertOk()->getContent();

    expect($html)->not->toContain(StylesDirective::PLACEHOLDER)
        ->and(substr_count($html, 'bw-root-'))->toBe(1)
        ->and(substr_count($html, 'bw-page-'))->toBe(1);
});

it('leaves the directive\'s own links standing, which is the full stylesheet, when page styles are disabled at runtime', function (): void {
    // In a real application the middleware is never registered with the package disabled, and the
    // directive's marked links are what the browser gets. Flipping the flag after boot leaves the
    // middleware in place, so PageStyles falls back to exactly those links instead.
    config()->set('bladewind.enabled', false);

    $html = (string) $this->get('/shaken')->assertOk()->getContent();

    expect($html)->toContain('href="'.asset('build/assets/app-test.css').'"')
        ->not->toContain(StylesDirective::PLACEHOLDER)
        ->not->toContain('bw-root-');
});

it('links the full stylesheet when page styles are disabled, when Vite is hot, and when the builder fails', function (): void {
    config()->set('bladewind.pages.enabled', false);
    $html = (string) $this->get('/shaken')->assertOk()->getContent();
    expect($html)->toContain('href="'.asset('build/assets/app-test.css').'"')->not->toContain('bw-root-')->not->toContain(StylesDirective::PLACEHOLDER);

    config()->set('bladewind.pages.enabled', true);
    file_put_contents($this->fixturePath('public/hot'), 'http://localhost:5173');

    try {
        $html = (string) $this->get('/shaken')->assertOk()->getContent();
        expect($html)->toContain('href="http://localhost:5173/resources/css/app.css"')->not->toContain('bw-root-');
    } finally {
        unlink($this->fixturePath('public/hot'));
    }

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'BW6001') && str_contains($message, 'boom'));

    // The stub inherits PageStyles' real collaborators (its fallback path is what is under test
    // here); only forResponse() is replaced. Resolved after Log::shouldReceive() so the logger it
    // holds is the mock the expectation above was set on.
    app()->instance(PageStyles::class, new class(app('config'), app(Vite::class), app(StylesheetIndex::class), app(DriverSelector::class), app(PageCssBuilder::class), app(ClassSetBuilder::class), app(PageStyleStore::class), app(LoggerInterface::class), app(SupportCssBuilder::class)) extends PageStyles
    {
        public function forResponse(string $html, array $renderedPaths): PageLinks
        {
            throw new RuntimeException('boom');
        }
    });

    $html = (string) $this->get('/shaken')->assertOk()->getContent();
    expect($html)->toContain('app-test.css')->not->toContain(StylesDirective::PLACEHOLDER)->not->toContain('bw-root-');
});

it('leaves the marked links in place when the page styles service itself cannot be built', function (): void {
    // A failure constructing PageStyles (a driver whose constructor throws at runtime) must not be
    // a 500: the directive's own links are a complete stylesheet, so the page keeps them.
    app()->bind(PageStyles::class, fn () => throw new RuntimeException('cannot build'));
    Route::get('/raw', fn () => '<html><head><link rel="stylesheet" href="/build/assets/app-test.css" '.StylesDirective::MARKER.'></head><body></body></html>');

    $response = $this->get('/raw')->assertOk();

    expect($response->getContent())->toContain('app-test.css" '.StylesDirective::MARKER.'>')
        ->not->toContain('bw-root-');
});

it('leaves the marked links in place when even the fallback cannot be produced', function (): void {
    // The last-resort case: nothing the middleware can offer. The directive's own output is a
    // complete stylesheet, so the page renders exactly as `@vite` would have made it.
    app()->instance(PageStyles::class, new class(app('config'), app(Vite::class), app(StylesheetIndex::class), app(DriverSelector::class), app(PageCssBuilder::class), app(ClassSetBuilder::class), app(PageStyleStore::class), app(LoggerInterface::class), app(SupportCssBuilder::class)) extends PageStyles
    {
        public function forResponse(string $html, array $renderedPaths): PageLinks
        {
            throw new RuntimeException('boom');
        }

        public function fallbackLinks(string $reason, bool $silent = false): PageLinks
        {
            throw new RuntimeException('boom again');
        }
    });

    $response = $this->get('/shaken')->assertOk();

    expect($response->getContent())->toContain('href="'.asset('build/assets/app-test.css').'" '.StylesDirective::MARKER.'>')
        ->not->toContain('bw-root-')
        ->and($response->headers->get('X-BladeWind-Styles'))->toBeNull();
});

it('registers itself on the global middleware stack, and not when bladewind is disabled', function (): void {
    $kernel = app(Kernel::class);

    expect($kernel)->toBeInstanceOf(HttpKernel::class)
        ->and($kernel->hasMiddleware(InjectPageStyles::class))->toBeTrue();

    config()->set('bladewind.enabled', false);
    $before = count($kernel->getGlobalMiddleware());

    (new BladeWindServiceProvider($this->app))->registerPageStylesMiddleware();

    expect(count($kernel->getGlobalMiddleware()))->toBe($before);
});

it('does not disturb Livewire updates', function (): void {
    $component = Livewire::test(Ticker::class);

    expect($component->html())->toContain('count 0')->not->toContain(StylesDirective::PLACEHOLDER);

    $component->call('tick')->assertSet('count', 1);

    // tick() re-renders the 'stats' island, so 'count 1' arrives in the island's fragment rather
    // than in the component's HTML, exactly as it does without page styles.
    $fragments = $component->instance()->getRenderedIslandFragments();
    $rendered = implode("\n", array_map(static fn (mixed $fragment): string => is_array($fragment) ? json_encode($fragment, JSON_THROW_ON_ERROR) : (string) $fragment, $fragments));

    expect($rendered)->toContain('count 1')->not->toContain(StylesDirective::PLACEHOLDER)
        ->and($component->html())->not->toContain(StylesDirective::PLACEHOLDER);
});

it('carries the page CSS in a style element and no page link when delivery is inline', function (): void {
    config()->set('bladewind.pages.delivery', 'inline');

    $response = $this->get('/shaken')->assertOk();
    $html = (string) $response->getContent();

    preg_match('~<style data-bladewind-page="([0-9a-f]{12})"[^>]*>(.*?)</style>~s', $html, $style);

    expect($html)->not->toContain(StylesDirective::MARKER)
        ->not->toContain('app-test.css')
        ->not->toContain('bw-page-')
        ->and(substr_count($html, '<style data-bladewind-page='))->toBe(1)
        ->and($style[2])->toContain('.flex{display:flex}')
        ->and($html)->toMatch('~<link rel="stylesheet" href="[^"]*/'.$this->assetsPath().'/bw-root-[0-9a-f]{12}\.css"><style data-bladewind-page=~')
        ->and($response->headers->get('X-BladeWind-Styles'))->toContain('delivery=inline');

    // The file is still the cache: a second request serves the same bytes from the memo, and the
    // file prune() works from is on disk either way.
    expect(app(PageStyleStore::class)->has('bw-page-'.app(PageStyleStore::class)->sheetId((string) app(PageStyles::class)->buildHash()).'-'.$style[1].'.css'))->toBeTrue();
});

it('carries the Vite CSP nonce on every generated tag when the app sets one', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/nonced', fn () => view('pages.shaken'));
    app(Vite::class)->useCspNonce('n0nce-value');

    $html = $this->get('/nonced')->assertOk()->getContent();
    preg_match_all('~<link rel="stylesheet" href="[^"]*/'.$this->assetsPath().'/bw-(?:root|page)-[^"]*"([^>]*)>~', $html, $links);

    expect($links[0])->toHaveCount(2)
        ->and($links[1])->each->toContain(' nonce="n0nce-value"')
        ->and($html)->not->toContain(StylesDirective::MARKER);

    config()->set('bladewind.pages.delivery', 'inline');
    $inline = $this->get('/nonced')->assertOk()->getContent();

    expect($inline)->toMatch('~<style data-bladewind-page="[0-9a-f]{12}" data-bladewind-nav="[0-9a-f]{6}" nonce="n0nce-value">~')
        ->toContain('<script data-bladewind-navigate nonce="n0nce-value">');

    config()->set('bladewind.pages.enabled', false);
    $fallback = $this->get('/nonced')->assertOk()->getContent();

    expect($fallback)->toMatch('~<link rel="stylesheet" href="[^"]*app-test\.css" nonce="n0nce-value">~');
});

it('emits no nonce attribute when the app sets none', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Route::get('/plain', fn () => view('pages.shaken'));

    expect($this->get('/plain')->assertOk()->getContent())->not->toContain('nonce=');
});

it('shows a metrics panel and always sends the header when the package debug mode is on', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('app.debug', false);
    Route::get('/metrics', fn () => view('pages.shaken'));

    $plain = $this->get('/metrics')->assertOk();
    expect($plain->getContent())->not->toContain('data-bladewind-panel')
        ->and($plain->headers->has('X-BladeWind-Styles'))->toBeFalse();

    config()->set('app.debug', true);
    $headerOnly = $this->get('/metrics')->assertOk();
    expect($headerOnly->headers->get('X-BladeWind-Styles'))->toStartWith('page=')
        ->and($headerOnly->getContent())->not->toContain('data-bladewind-panel');

    config()->set('app.debug', false);
    config()->set('bladewind.debug', true);
    $first = $this->get('/metrics')->assertOk();
    $html = (string) $first->getContent();
    preg_match('~<details data-bladewind-panel.*?</details>~s', $html, $panel);

    expect($first->headers->get('X-BladeWind-Styles'))->toStartWith('page=')
        ->and($panel)->not->toBeEmpty()
        ->and($panel[0])->not->toContain('class=')
        ->and($panel[0])->not->toContain('<script')
        ->and($panel[0])->toContain('BladeWind')
        ->and($panel[0])->toMatch('~Full stylesheet.*?\d[\d,]*\.\d KB~s')
        ->and($panel[0])->toMatch('~<b>\d[\d,]*\.\d KB</b><small>of \d[\d,]*\.\d KB</small>~')
        ->and($panel[0])->toMatch('~data-bw="delta">−\d+%<~')
        ->and($panel[0])->toContain('<style>')
        ->and($panel[0])->toContain('&lt;link&gt; to a cached file')
        ->and($panel[0])->toContain('Tailwind CSS 4')
        ->and($panel[0])->toContain('title=')
        ->and(strrpos($html, 'data-bladewind-panel'))->toBeLessThan((int) strrpos($html, '</body>'))
        // The panel is added after the class set was built, so it never changes the page's set.
        ->and(substr((string) $first->headers->get('X-BladeWind-Styles'), 0, 17))->toBe(substr((string) $this->get('/metrics')->headers->get('X-BladeWind-Styles'), 0, 17));

    $panelHit = null;
    preg_match('~<details data-bladewind-panel.*?</details>~s', (string) $this->get('/metrics')->getContent(), $panelHit);
    expect($panelHit[0] ?? '')->toContain('reused from disk');

    config()->set('bladewind.pages.enabled', false);
    preg_match('~<details data-bladewind-panel.*?</details>~s', (string) $this->get('/metrics')->getContent(), $fallback);
    expect($fallback[0] ?? '')->toContain('full stylesheet served instead')->toContain('page styles are turned off')->toContain('pages.enabled');

    $this->get('/json')->assertOk()->assertExactJson(['ok' => true]);
});

it('leaves the debug-only work out of the time the panel shows', function (): void {
    config()->set('bladewind.debug', true);

    // The real links, reported as if every millisecond of them had gone on debug-only work: what
    // the panel shows is then the timer less that overhead, floored at zero.
    app()->instance(PageStyles::class, new class(app('config'), app(Vite::class), app(StylesheetIndex::class), app(DriverSelector::class), app(PageCssBuilder::class), app(ClassSetBuilder::class), app(PageStyleStore::class), app(LoggerInterface::class), app(SupportCssBuilder::class)) extends PageStyles
    {
        public function forResponse(string $html, array $renderedPaths): PageLinks
        {
            $links = parent::forResponse($html, $renderedPaths);

            return new PageLinks($links->html, $links->header, $links->fallback, $links->metrics, debugMilliseconds: 60_000.0);
        }
    });

    preg_match('~<details data-bladewind-panel.*?</details>~s', (string) $this->get('/shaken')->assertOk()->getContent(), $panel);

    expect($panel[0] ?? '')->toContain(' · 0.0 ms')->toContain('only this panel and the debug header need');
});
