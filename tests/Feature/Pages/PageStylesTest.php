<?php

declare(strict_types=1);

use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Pages\Drivers\DriverSelector;
use Daikazu\BladeWind\Pages\Drivers\FlatDriver;
use Daikazu\BladeWind\Pages\FlatStylesheetSplitter;
use Daikazu\BladeWind\Pages\NavigateScript;
use Daikazu\BladeWind\Pages\PageLinks;
use Daikazu\BladeWind\Pages\PageStyles;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Pages\SplitStylesheet;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * @var list<string> scratch public paths to remove after each test
 */
$scratchPaths = [];

/**
 * Points the application at a scratch public path holding its own Vite manifest and stylesheets,
 * and forgets the services built from the old one. An empty $stylesheets writes no manifest at all,
 * which is what a deployment that never ran `vite build` looks like.
 *
 * @param  array<string, string>  $stylesheets  Vite entry name => stylesheet contents, in order
 * @param  list<string>|null  $configured  `bladewind.stylesheets`; defaults to the entry names
 */
function pageStylesScratch(array $stylesheets, ?array $configured = null): string
{
    $files = new Filesystem;
    $scratch = sys_get_temp_dir().'/bladewind-pages-'.bin2hex(random_bytes(6));
    $manifest = [];
    $position = 0;

    $files->ensureDirectoryExists($scratch.'/build/assets');

    foreach ($stylesheets as $name => $css) {
        $file = 'assets/sheet-'.(++$position).'.css';
        $files->put($scratch.'/build/'.$file, $css);
        $manifest[$name] = ['file' => $file, 'src' => $name, 'isEntry' => true];
    }

    if ($manifest !== []) {
        $files->put($scratch.'/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    config()->set('bladewind.stylesheets', $configured ?? array_keys($stylesheets));
    app()->usePublicPath($scratch);

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    return $scratch;
}

/**
 * The generated file of one kind ("root" or "page") this response linked, by name.
 */
function pageStylesFile(PageLinks $links, string $kind): string
{
    preg_match('~bw-'.$kind.'-[0-9a-f-]+\.css~', $links->html, $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

/**
 * The page file behind an inlined `<style>` element, by name: the style element carries the set id
 * the file name ends with, so the file the response *did not* link is still reachable from it.
 */
function pageStylesInlineFile(PageLinks $links): string
{
    preg_match('~<style data-bladewind-page="([0-9a-f]{12})"[^>]*>~', $links->html, $match);

    expect($match)->not->toBeEmpty();

    return 'bw-page-'.app(PageStyleStore::class)->sheetId((string) app(PageStyles::class)->buildHash()).'-'.$match[1].'.css';
}

/**
 * The contents of the two files this response linked, root first.
 *
 * @return array{0: string, 1: string}
 */
function pageStylesFiles(PageLinks $links): array
{
    $directory = app(PageStyleStore::class)->directory();

    return [
        (string) file_get_contents($directory.'/'.pageStylesFile($links, 'root')),
        (string) file_get_contents($directory.'/'.pageStylesFile($links, 'page')),
    ];
}

/**
 * The compiled stylesheet a fixture holds, which is the oracle the root-and-page invariant checks
 * the two generated files against.
 */
function pageStylesCss(string $fixture): string
{
    return (string) file_get_contents($fixture);
}

afterEach(function () use (&$scratchPaths): void {
    foreach ($scratchPaths as $path) {
        (new Filesystem)->deleteDirectory($path);
    }

    $scratchPaths = [];
});

it('logs BW6001 when no stylesheet at all can be linked, however silent the reason', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'BW6001') && str_contains($message, 'no stylesheet could be linked (disabled)'),
    );

    $scratchPaths[] = pageStylesScratch([], ['resources/css/app.css']);
    config()->set('bladewind.pages.enabled', false);

    $styles = app(PageStyles::class);
    $links = $styles->forResponse('<div class="flex"></div>', []);
    // Still once per process, even though the empty fallback is what forced the log.
    $styles->forResponse('<div class="flex"></div>', []);

    expect($links->html)->toBe('')
        ->and($links->fallback)->toBeTrue()
        ->and($links->header)->toBe('fallback=disabled');
});

it('falls back with one BW6002 naming what was looked for when no driver recognises the stylesheet', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'BW6002')
            && str_contains($message, 'resources/css/app.css')
            && str_contains($message, 'has no shape a CSS framework driver recognises (looked for: top-level @layer utilities block'),
    );

    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => '@layer theme{:root{--spacing:.25rem}}.flex{display:flex}']);

    $styles = app(PageStyles::class);
    $first = $styles->forResponse('<div class="flex"></div>', []);
    $second = $styles->forResponse('<div class="flex"></div>', []);

    expect($first->fallback)->toBeTrue()
        ->and($first->header)->toBe('fallback=stylesheet not recognised')
        ->and($first->html)->toBe('<link rel="stylesheet" href="'.asset('build/assets/sheet-1.css').'">')
        ->and($second->html)->toBe($first->html);
});

it('falls back with one BW6002 from the configured driver when it finds nothing to split', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => $message === 'BladeWind BW6002: Stylesheet resources/css/app.css has no top-level @layer utilities block; page styles are disabled for it.',
    );

    // Named outright, the driver is not asked whether it recognises the stylesheet: it splits it,
    // finds no utilities layer, and says so in its own words.
    config()->set('bladewind.framework', 'tailwind4');
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => '@layer theme{:root{--spacing:.25rem}}.flex{display:flex}']);

    $styles = app(PageStyles::class);
    $first = $styles->forResponse('<div class="flex"></div>', []);
    $styles->forResponse('<div class="flex"></div>', []);

    expect($first->fallback)->toBeTrue()
        ->and($first->header)->toBe('fallback=nothing to split');
});

it('names the driver the stylesheet was taken apart with in the header', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=tailwind4 ');
});

it('links every other configured stylesheet after the root and page files', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(
        [
            'resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css')),
            'resources/css/extra.css' => '@layer utilities{.mt-2{margin-top:2px}}',
        ],
        ['resources/css/app.css', 'resources/css/extra.css', 'resources/css/absent.css'],
    );

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($links->fallback)->toBeFalse()
        ->and($links->html)->toMatch('~^<link rel="stylesheet" href="[^"]*/bw-root-[0-9a-f]{12}\.css"><link rel="stylesheet" href="[^"]*/bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css" data-bladewind-nav="[0-9a-f]{6}"><link rel="stylesheet" href="[^"]*/build/assets/sheet-2\.css"><script src="[^"]*/bw-navigate-[0-9a-f]{12}\.js" data-bladewind-navigate defer></script>$~s')
        // The entry the manifest does not carry is skipped rather than failing the response.
        ->and($links->html)->not->toContain('absent');
});

it('names new files when a secondary stylesheet changes, since the root defines what it reads', function () use (&$scratchPaths): void {
    $primary = (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'));
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => $primary, 'resources/css/prose.css' => '.prose{x:1}']);
    $before = pageStylesFile(app(PageStyles::class)->forResponse('<div class="flex"></div>', []), 'root');

    // Only the secondary changed; the primary's hash did not, and a root named by that hash alone
    // would keep serving a file that defines nothing the new secondary reads.
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => $primary, 'resources/css/prose.css' => '.prose{border-radius:var(--radius-lg)}']);
    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    [$root] = pageStylesFiles($links);

    expect(pageStylesFile($links, 'root'))->not->toBe($before)
        ->and($root)->toContain('--radius-lg:.5rem');
});

it('names new files when a different driver takes the stylesheet apart', function () use (&$scratchPaths): void {
    // A flat driver's split of Tailwind 4 output differs from the Tailwind 4 driver's (no shaking,
    // its runtime tokens added); the files must not be shared between the two.
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);
    $auto = pageStylesFile(app(PageStyles::class)->forResponse('<div class="flex"></div>', []), 'root');

    config()->set('bladewind.framework', 'bootstrap5');
    app()->forgetInstance(PageStyles::class);
    $named = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($named->fallback)->toBeFalse()
        ->and($named->header)->toContain('framework=bootstrap5')
        ->and(pageStylesFile($named, 'root'))->not->toBe($auto);
});

it('defines in the root every variable a secondary stylesheet reads', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch([
        'resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css')),
        'resources/css/prose.css' => '.prose{border-radius:var(--radius-lg)}',
    ]);

    // Nothing on the page uses `rounded-lg`, so nothing in its own files would carry `--radius-lg`;
    // the secondary stylesheet, linked whole after them, reads it all the same.
    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    [$root, $page] = pageStylesFiles($links);

    expect($links->fallback)->toBeFalse()
        ->and($root)->toContain('--radius-lg:.5rem')
        ->and($page)->not->toContain('--radius-lg');
});

it('falls back to the full stylesheet when a rendered view has no analysis entry, logging BW6003 once', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'BW6003')
            && str_contains($message, '1 rendered view has no analysis entry')
            && str_contains($message, '/vendor/acme/ui/src/pagination.blade.php')
            && $context['paths'] === ['/vendor/acme/ui/src/pagination.blade.php'],
    );

    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    // The tile is analysed; the vendor view is one the locator will not describe. Its @else
    // branches and runtime classes are invisible, so the page cannot be built safely from what
    // rendered: the full stylesheet is served instead, and the log says which view to cover.
    $path = $this->fixturePath('views/components/tile.blade.php');
    app(ManifestStore::class)->put(analyzeFixture('views/components/tile.blade.php'));
    $styles = app(PageStyles::class);
    $rendered = [$path, '/vendor/acme/ui/src/pagination.blade.php'];

    $links = $styles->forResponse('<div class="flex"></div>', $rendered);
    $styles->forResponse('<div class="flex"></div>', $rendered);

    expect($links->fallback)->toBeTrue()
        ->and($links->header)->toBe('fallback=rendered views have no analysis entry')
        ->and($links->html)->toContain('sheet-1.css')->not->toContain('bw-page-');
});

it('builds a page from its HTML alone when pages.unanalysed is html, logging BW6003 only when nothing was analysed', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'BW6003')
            && str_contains($message, '2 rendered views have no analysis entry')
            && $context['analysed'] === '0' && $context['unanalysed'] === '2',
    );

    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);
    config()->set('bladewind.pages.unanalysed', 'html');

    $styles = app(PageStyles::class);
    $rendered = ['/vendor/acme/ui/src/pagination.blade.php', '/vendor/acme/ui/src/modal.blade.php'];
    $links = $styles->forResponse('<div class="flex"></div>', $rendered);
    $styles->forResponse('<div class="flex"></div>', $rendered);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain('analysed=0 unanalysed=2');

    // One unanalysed view among analysed ones stays in the header and is not logged.
    $path = $this->fixturePath('views/components/tile.blade.php');
    app(ManifestStore::class)->put(analyzeFixture('views/components/tile.blade.php'));

    $mixed = $styles->forResponse('<div class="flex"></div>', [$path, '/vendor/acme/ui/src/pagination.blade.php']);

    expect($mixed->fallback)->toBeFalse()
        ->and($mixed->header)->toContain('analysed=1 unanalysed=1');
});

it('does not fall back for a view rendered from a string, which has no source to analyse', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->never();

    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    // Blade::render() compiles the string to a file under view.compiled; its classes are in the
    // HTML and there is no other source, so the page is built from the HTML alone.
    $string = rtrim((string) config('view.compiled'), '/').'/'.hash('xxh128', 'string').'.blade.php';
    file_put_contents($string, '<div class="flex"></div>');

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', [$string]);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain('analysed=0 unanalysed=1');
});

it('carries safelisted tokens in every page file, exact and by prefix', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);
    config()->set('bladewind.safelist', ['opacity-50', 'sm:*']);

    // Nothing rendered mentions any of these: they reach the page through the safelist alone, the
    // prefix pattern expanded against the classes the stylesheet actually has rules for.
    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    [, $page] = pageStylesFiles($links);

    expect($links->fallback)->toBeFalse()
        ->and($page)->toContain('.opacity-50{')->toContain('.sm\\:flex{')->toContain('.sm\\:gap-4{')
        ->not->toContain('.hover\\:bg-gray-100');

    // A different safelist is a different page file.
    config()->set('bladewind.safelist', ['opacity-50']);
    $again = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    [, $second] = pageStylesFiles($again);

    expect(pageStylesFile($again, 'page'))->not->toBe(pageStylesFile($links, 'page'))
        ->and($second)->toContain('.opacity-50{')->not->toContain('.sm\\:gap-4{');
});

it('carries a class-less rule from a real Tailwind build into every page file', function (): void {
    // tests/fixtures/public-tailwind holds output the real Tailwind compiler produced from an
    // input.css whose own `@layer utilities { [x-cloak] {...} }` block compiles into a class-less
    // rule inside the utilities layer. A page that never mentions x-cloak still needs that rule.
    app()->usePublicPath($this->fixturePath('public-tailwind'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);

    expect($links->fallback)->toBeFalse()
        ->and($page)->not->toBeEmpty();

    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);

    expect($pageCss)->toContain('[x-cloak]{display:none!important}')
        ->toContain('.flex{display:flex}')
        ->not->toContain('.animate-spin');
});

it('serves a real Tailwind 3 build as a bare root and page with no layer anywhere', function (): void {
    // tests/fixtures/public-tailwind3 is output the real Tailwind 3 compiler produced: no cascade
    // layers, the `--tw-*` defaults in a plain rule, the keyframe beside the utility that uses it.
    // Detected, not configured: `bladewind.framework` is still `auto` here.
    app()->usePublicPath($this->fixturePath('public-tailwind3'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $html = '<div class="flex animate-spin dark:bg-black" style="color:var(--brand)"></div>';
    $links = app(PageStyles::class)->forResponse($html, []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-root-[0-9a-f]{12}\.css~', $links->html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=tailwind3 ')
        ->and($links->header)->toContain(' support=0 ')
        ->and($root)->not->toBeEmpty()
        ->and($page)->not->toBeEmpty();

    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);
    $stylesheet = (string) file_get_contents($this->fixturePath('public-tailwind3/build/assets/app-tailwind3.css'));

    expect($rootCss)->toContain('*,:before,:after,::backdrop{--tw-border-spacing-x:0;')
        ->toContain(':root{--brand:#0af}')
        ->toEndWith('@keyframes spin{to{transform:rotate(360deg)}}')
        ->not->toContain('.flex{')
        ->and($pageCss)->toContain('.flex{display:flex}')
        ->toContain('.animate-spin{animation:1s linear infinite spin}')
        ->toContain('.dark\:bg-black:is(.dark *){')
        ->toContain('[x-cloak]{display:none!important}')
        ->not->toContain('.items-center')
        ->not->toContain('@keyframes')
        ->and($rootCss.$pageCss)->not->toContain('@layer');

    assertNoUndefinedVariables($rootCss, $pageCss, $stylesheet, $html);
});

it('serves the Tachyons stylesheet as a bare root and page, recognised by its banner', function (): void {
    // tests/fixtures/public-tachyons is the Tachyons npm package's own minified build: normalize
    // first, then every utility, no layers and no custom properties anywhere.
    app()->usePublicPath($this->fixturePath('public-tachyons'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $links = app(PageStyles::class)->forResponse('<div class="flex pa3 hover-white flex-ns"></div>', []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-root-[0-9a-f]{12}\.css~', $links->html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=tachyons ')
        ->and($root)->not->toBeEmpty()
        ->and($page)->not->toBeEmpty();

    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);

    expect($rootCss)->toStartWith('/*! TACHYONS v4.12')
        ->toContain('html{')
        ->not->toContain('.border-box')
        ->and($pageCss)->toContain('.flex{display:flex}')
        ->toContain('.pa3{padding:1rem}')
        ->toContain('.hover-white:hover{color:#fff}')
        ->toContain('@media screen and (min-width:30em){.flex-ns{display:flex}}')
        // A class-less rule after the first class rule reaches every page from where it fell, and so
        // does the cut rule itself: its element selectors size every element on the page.
        ->toContain('img{max-width:100%}')
        ->toContain('.border-box,a,article,aside,blockquote,body,code,')
        ->not->toContain('.pa4{')
        ->and($rootCss.$pageCss)->not->toContain('@layer');
});

it('serves Bootstrap 5 with its JavaScript-only classes in every page file', function (): void {
    // tests/fixtures/public-bootstrap5 is Bootstrap's own dist/css/bootstrap.min.css. Nothing on
    // this page mentions modal-backdrop, collapsing or tooltip: Bootstrap's JavaScript creates
    // those elements, so the driver's runtime tokens have to bring their rules along.
    app()->usePublicPath($this->fixturePath('public-bootstrap5'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $html = '<div class="container"><button class="btn btn-primary" data-bs-toggle="tooltip" title="x">x</button></div>';
    $links = app(PageStyles::class)->forResponse($html, []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-root-[0-9a-f]{12}\.css~', $links->html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);
    preg_match('~ tokens=(\d+) ~', $links->header, $tokens);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=bootstrap5 ')
        // Three classes in the markup; the rest of the count is the driver's runtime tokens.
        ->and((int) ($tokens[1] ?? 0))->toBeGreaterThan(30)
        ->and($root)->not->toBeEmpty()
        ->and($page)->not->toBeEmpty();

    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);
    $stylesheet = (string) file_get_contents($this->fixturePath('public-bootstrap5/build/assets/app-bootstrap5.css'));

    expect($rootCss)->toStartWith('@charset "UTF-8";/*!')
        ->toContain(':root,[data-bs-theme=light]{--bs-blue:#0d6efd;')
        ->toContain('*,::after,::before{box-sizing:border-box}')
        ->toContain('@keyframes spinner-border{')
        ->not->toContain('.btn{')
        ->and($pageCss)->toContain('.btn{')
        ->toContain('.btn-primary{')
        ->toContain('.btn-check:checked+.btn,')
        ->toContain('.modal-backdrop{')
        ->toContain('.collapsing{')
        ->toContain('.tooltip{')
        ->toContain('.h1,.h2,.h3,.h4,.h5,.h6,h1,h2,h3,h4,h5,h6{')
        ->not->toContain('.navbar{')
        ->not->toContain('.card{')
        ->and($rootCss.$pageCss)->not->toContain('@layer');

    assertNoUndefinedVariables($rootCss, $pageCss, $stylesheet, $html);
});

it('marks the page asset per response so wire:navigate appends it last, and ships the script that prunes the stale ones', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    $styles = app(PageStyles::class);
    $first = $styles->forResponse('<div class="flex"></div>', []);
    $second = $styles->forResponse('<div class="flex"></div>', []);

    preg_match_all('~<link rel="stylesheet" href="([^"]*)"( data-bladewind-nav="([0-9a-f]{6})")?>~', $first->html, $firstLinks, PREG_SET_ORDER);
    preg_match_all('~<link rel="stylesheet" href="([^"]*)"( data-bladewind-nav="([0-9a-f]{6})")?>~', $second->html, $secondLinks, PREG_SET_ORDER);

    // Same set, same files: the root link is byte-identical (Livewire keeps it in place, once), the
    // page link differs only by its marker (Livewire appends it again, last), and the script is the
    // same text each time (Livewire runs it once).
    expect($firstLinks)->toHaveCount(2)
        ->and($firstLinks[0][0])->toBe($secondLinks[0][0])
        ->and($firstLinks[0][0])->not->toContain('data-bladewind-nav')
        ->and($firstLinks[1][1])->toBe($secondLinks[1][1])
        ->and($firstLinks[1][3])->not->toBe($secondLinks[1][3])
        // Without a CSP nonce the script is external, so a `script-src 'self'` policy runs it; its
        // source is what the asset route serves.
        ->and(substr_count($first->html, ' data-bladewind-navigate defer></script>'))->toBe(1)
        ->and($first->html)->toMatch('~<script src="[^"]*/bw-navigate-[0-9a-f]{12}\.js" data-bladewind-navigate defer></script>$~')
        ->and(NavigateScript::SOURCE)->toContain("addEventListener('livewire:navigated'")->toContain('[data-bladewind-nav]')
        ->and(substr($first->html, strpos($first->html, '<script')))->toBe(substr($second->html, strpos($second->html, '<script')));
});

it('emits no navigate script on the fallback path', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => '.a{x:y}']);

    $links = app(PageStyles::class)->forResponse('<div class="a"></div>', []);

    expect($links->fallback)->toBeTrue()
        ->and($links->html)->not->toContain('<script')
        ->and($links->html)->not->toContain('data-bladewind-nav');
});

it('carries Bootstrap\'s JavaScript-only classes even when a --tw- snippet makes tailwind3 claim the bundle', function () use (&$scratchPaths): void {
    $bootstrap = (string) file_get_contents($this->fixturePath('public-bootstrap5/build/assets/app-bootstrap5.css'));
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => $bootstrap.'.ring{box-shadow:var(--tw-ring-shadow)}']);

    $links = app(PageStyles::class)->forResponse('<div class="btn"></div>', []);
    [, $page] = pageStylesFiles($links);

    expect($links->header)->toContain('framework=tailwind3')
        ->and($page)->toContain('.modal-backdrop{');
});

it('serves Bulma with its theme rules in every page file', function (): void {
    // tests/fixtures/public-bulma is Bulma's own css/bulma.min.css. Its first class rule is the
    // light theme's variables beside `[data-theme=light]`, so the cut falls there and the theme
    // rules, like minireset after them, reach every page unconditionally.
    app()->usePublicPath($this->fixturePath('public-bulma'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $html = '<div class="container"><button class="button is-primary">x</button></div>';
    $links = app(PageStyles::class)->forResponse($html, []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-root-[0-9a-f]{12}\.css~', $links->html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=bulma ')
        ->and($root)->not->toBeEmpty()
        ->and($page)->not->toBeEmpty();

    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);
    $stylesheet = (string) file_get_contents($this->fixturePath('public-bulma/build/assets/app-bulma.css'));

    expect($rootCss)->toStartWith('@charset "UTF-8";')
        ->toContain(':root{--bulma-control-radius:')
        ->toContain('@media (prefers-color-scheme:dark){:root{')
        ->toContain('@keyframes spinAround{')
        ->not->toContain('.theme-light')
        ->not->toContain('.button{')
        ->and($pageCss)->toStartWith('.theme-light,[data-theme=light]{')
        ->toContain('.theme-dark,[data-theme=dark]{')
        ->toContain('blockquote,body,dd,dl,dt,fieldset,figure,h1,h2,h3,h4,h5,h6,hr,html,iframe,legend,li,ol,p,pre,textarea,ul{')
        ->toContain('.button{')
        ->toContain('.button.is-primary{')
        ->toContain('.container{')
        ->not->toContain('.navbar{')
        ->not->toContain('.modal{')
        ->and($rootCss.$pageCss)->not->toContain('@layer');

    assertNoUndefinedVariables($rootCss, $pageCss, $stylesheet, $html);
});

it('serves Foundation with an almost empty root and its JavaScript-only classes in every page', function (): void {
    // tests/fixtures/public-foundation is Foundation's own dist/css/foundation.min.css, whose first
    // block after @charset is a media query of .reveal rules: the cut falls there, the root is the
    // charset alone, and normalize reaches every page unconditionally from where it falls.
    app()->usePublicPath($this->fixturePath('public-foundation'));

    foreach ([StylesheetIndex::class, PageStyleStore::class, PageStyles::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $html = '<div class="grid-container"><button class="button primary" data-open="m">x</button></div>';
    $links = app(PageStyles::class)->forResponse($html, []);
    $store = app(PageStyleStore::class);

    preg_match('~bw-root-[0-9a-f]{12}\.css~', $links->html, $root);
    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);

    expect($links->fallback)->toBeFalse()
        ->and($links->header)->toContain(' framework=foundation ')
        ->and($root)->not->toBeEmpty()
        ->and($page)->not->toBeEmpty();

    $rootCss = (string) file_get_contents($store->directory().'/'.$root[0]);
    $pageCss = (string) file_get_contents($store->directory().'/'.$page[0]);
    $stylesheet = (string) file_get_contents($this->fixturePath('public-foundation/build/assets/app-foundation.css'));

    expect($rootCss)->toBe('@charset "UTF-8";')
        ->and($pageCss)->toContain('html{line-height:1.15;')
        ->toContain('.button{')
        ->toContain('.button.primary,')
        ->toContain('.grid-container{')
        ->toContain('.reveal-overlay{')
        ->toContain('.is-reveal-open')
        ->toContain('.tooltip{')
        ->not->toContain('.top-bar{')
        ->not->toContain('.callout{')
        ->and($rootCss.$pageCss)->not->toContain('@layer');

    assertNoUndefinedVariables($rootCss, $pageCss, $stylesheet, $html);
});

it('marks a page file as used when a hit finds it stale', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    $styles = app(PageStyles::class);
    $store = app(PageStyleStore::class);
    $links = $styles->forResponse('<div class="flex"></div>', []);

    preg_match('~bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css~', $links->html, $page);
    $path = $store->directory().'/'.$page[0];
    $old = time() - PageStyleStore::GRACE_SECONDS - 60;
    touch($path, $old);

    // The busiest page is the one the cap would otherwise delete first, because it was created
    // first and nothing since has moved its mtime.
    $styles->forResponse('<div class="flex"></div>', []);
    clearstatcache(true, $path);

    expect(filemtime($path))->toBeGreaterThan($old)
        ->and(file_get_contents($path))->toContain('.flex{display:flex}');
});

it('stops logging once fifty distinct reasons have been seen', function (): void {
    Log::shouldReceive('warning')->times(50)->withArgs(
        fn (string $message): bool => str_contains($message, 'BW6001') && str_contains($message, 'Page styles fell back'),
    );
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'will stay silent from here'),
    );

    $styles = app(PageStyles::class);

    foreach (range(1, 60) as $reason) {
        $styles->fallbackLinks('reason '.$reason);
    }

    // A reason already in the memo never logs again either.
    $styles->fallbackLinks('reason 1');
});

it('ships the root only the support its own layers read, and each page the rest of what it uses', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    $links = app(PageStyles::class)->forResponse('<div class="flex animate-spin gap-2 border rounded-lg space-y-6 text-red-500/50 hover:bg-gray-100 dark:bg-black"></div>', []);

    [$rootCss, $pageCss] = pageStylesFiles($links);

    // The base layer's `font-family:var(--default-font-family,...)` is the whole of the root's own
    // reach, so its theme block holds those four names and its properties layer stays a statement.
    expect($rootCss)->toContain('@layer theme{:root,:host{--font-sans:-apple-system')
        ->toContain('--default-font-family:var(--font-sans);--default-mono-font-family:var(--font-mono)}}')
        ->toContain('@layer properties;')
        ->not->toContain('--color-red-500')
        ->not->toContain('--spacing:')
        ->not->toContain('@property')
        // Every keyframe ships with the root, whether or not the root's own rules animate anything.
        ->toEndWith('@keyframes spin{to{transform:rotate(360deg)}}');

    // The page opens the properties layer before the theme layer, as the stylesheet itself did, and
    // closes with the `@property` registrations its utilities reach.
    expect($pageCss)->toStartWith('@layer properties{@supports (((-webkit-hyphens:none))')
        ->toContain('{*,:before,:after,::backdrop{--tw-space-y-reverse:0;--tw-border-style:solid}}}@layer theme{:root,:host{')
        ->toContain('--color-red-500:oklch(63.7% .237 25.331);--color-gray-100:oklch(96.7% .003 264.542);--color-black:#000;--spacing:.25rem;--radius-lg:.5rem;--animate-spin:spin 1s linear infinite}}@layer utilities{')
        ->not->toContain('--font-sans')
        ->not->toContain('@keyframes')
        ->toEndWith('@property --tw-space-y-reverse{syntax:"*";inherits:false;initial-value:0}'
            .'@property --tw-border-style{syntax:"*";inherits:false;initial-value:solid}');

    // Six theme variables and two `--tw-*` defaults, which is what the header counts.
    expect($links->header)->toContain('support=8');

    assertNoUndefinedVariables($rootCss, $pageCss, pageStylesCss($this->fixturePath('public-tailwind/build/assets/app-tailwind.css')));
});

it('counts the support a cached page carries only while app.debug is on', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    config()->set('app.debug', true);
    $styles = app(PageStyles::class);
    $html = '<div class="flex animate-spin border"></div>';

    $written = $styles->forResponse($html, []);
    $cached = $styles->forResponse($html, []);

    // A hit skips the utilities index the count is derived from, so it is recomputed for the header,
    // which is only sent under app.debug and only then worth paying for.
    // `--animate-spin` and `--tw-border-style`, which is all this page's three utilities read.
    expect($written->header)->toContain('support=2')
        ->and($cached->header)->toContain('support=2');

    config()->set('app.debug', false);

    expect($styles->forResponse($html, [])->header)->toContain('support=0 delivery=link root=0 page=0');
});

it('reports the time spent on debug-only work so the panel can leave it out', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    $styles = app(PageStyles::class);
    $html = '<div class="flex animate-spin border"></div>';

    // Neither the header's support count nor the panel's sizes are computed: nothing to leave out.
    expect($styles->forResponse($html, [])->debugMilliseconds)->toBe(0.0)
        ->and($styles->forResponse($html, [])->debugMilliseconds)->toBe(0.0);

    config()->set('app.debug', true);
    config()->set('bladewind.debug', true);

    // A write gzips three stylesheets for the panel; a hit also recomputes the support count for
    // the header. Both are work a production request never does, so both are reported.
    expect($styles->forResponse('<div class="flex border-2"></div>', [])->debugMilliseconds)->toBeGreaterThan(0.0)
        ->and($styles->forResponse($html, [])->debugMilliseconds)->toBeGreaterThan(0.0);
});

it('reports support=full and logs one BW6005 for a stylesheet whose support layers could not be shaken', function () use (&$scratchPaths): void {
    // A theme block declaring something that is not a custom property is a shape the splitter does
    // not take apart: the root keeps every support declaration and the page carries utilities and
    // nothing else. Outside a debug header nothing would say so, which is why the splitter's reason
    // is logged, once per process however many pages render.
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'BW6005')
            && str_contains($message, 'keeps its theme and properties layers whole: non-custom declaration in @layer theme')
            && $context === ['name' => 'resources/css/app.css', 'reason' => 'non-custom declaration in @layer theme'],
    );

    $scratchPaths[] = pageStylesScratch([
        'resources/css/app.css' => '@layer theme{:root{--spacing:.25rem;color:red}}@layer utilities{.gap-2{gap:calc(var(--spacing) * 2)}}',
    ]);

    $styles = app(PageStyles::class);
    $links = $styles->forResponse('<div class="gap-2"></div>', []);
    $styles->forResponse('<div class="gap-2"></div>', []);
    [$rootCss, $pageCss] = pageStylesFiles($links);

    expect($links->header)->toContain('support=full')
        ->and($rootCss)->toContain('--spacing:.25rem')
        ->and($pageCss)->toBe('@layer utilities{.gap-2{gap:calc(var(--spacing) * 2)}}');
});

it('keeps the variables the configuration names, in their own page file', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    $html = '<div class="flex"></div>';
    $plain = app(PageStyles::class)->forResponse($html, []);

    // JavaScript reading `--color-red-500` by name is invisible from here; the configuration is what
    // keeps it, and because it joins the class set as a pseudo-token the page gets its own file.
    config()->set('bladewind.pages.keep_variables', ['--color-red-*']);
    app()->forgetInstance(PageStyles::class);

    $kept = app(PageStyles::class)->forResponse($html, []);

    [$plainRoot, $plainCss] = pageStylesFiles($plain);
    [, $keptCss] = pageStylesFiles($kept);

    expect(pageStylesFile($kept, 'page'))->not->toBe(pageStylesFile($plain, 'page'))
        ->and(pageStylesFile($kept, 'root'))->toBe(pageStylesFile($plain, 'root'))
        ->and($plainCss)->not->toContain('--color-red-500')
        ->and($keptCss)->toContain('@layer theme{:root,:host{--color-red-500:oklch(63.7% .237 25.331)}}')
        ->and($plainRoot)->not->toContain('--color-red-500');

    assertNoUndefinedVariables($plainRoot, $keptCss, pageStylesCss($this->fixturePath('public-tailwind/build/assets/app-tailwind.css')));
});

it('pulls a variable the document itself names into that page file', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    $styles = app(PageStyles::class);
    $plain = $styles->forResponse('<div class="flex"></div>', []);
    $inline = $styles->forResponse('<div class="flex" style="color:var(--color-red-500)"></div>', []);

    [, $plainCss] = pageStylesFiles($plain);
    [$rootCss, $inlineCss] = pageStylesFiles($inline);

    expect(pageStylesFile($inline, 'page'))->not->toBe(pageStylesFile($plain, 'page'))
        ->and($inlineCss)->toContain('--color-red-500:oklch(63.7% .237 25.331)')
        ->and($inlineCss)->not->toContain('--color-gray-100')
        ->and($plainCss)->not->toContain('--color-red-500');

    assertNoUndefinedVariables($rootCss, $inlineCss, pageStylesCss($this->fixturePath('public-tailwind/build/assets/app-tailwind.css')));
});

it('gives no page a file of its own for a custom property the stylesheet never declares', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    $styles = app(PageStyles::class);
    $plain = $styles->forResponse('<div class="flex"></div>', []);
    $undeclared = $styles->forResponse('<div class="flex" style="--progress:42%"></div>', []);
    $declared = $styles->forResponse('<div class="flex" style="color:var(--color-red-500)"></div>', []);

    // Nothing in the stylesheet declares `--progress`, so there is nothing to emit for it and both
    // files would be byte-identical; as a pseudo-token it would still have cost a second file, and
    // a value written from user data would cost one per record against `pages.max_files`.
    expect(pageStylesFile($undeclared, 'page'))->toBe(pageStylesFile($plain, 'page'))
        ->and(pageStylesFile($declared, 'page'))->not->toBe(pageStylesFile($plain, 'page'));
});

it('falls back with BW6002 when a driver returns a split with nothing in it', function () use (&$scratchPaths): void {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'BW6002') && str_contains($message, 'empty'));

    $driver = new class(new FlatStylesheetSplitter) extends FlatDriver
    {
        public function name(): string
        {
            return 'hollow';
        }

        public function detect(string $css): bool
        {
            return true;
        }

        public function split(string $css): SplitStylesheet
        {
            return new SplitStylesheet('', '', true, supportShaken: true);
        }
    };
    config()->set('bladewind.drivers', [$driver]);
    app()->forgetInstance(DriverSelector::class);
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => 'html{color:red}.flex{display:flex}']);

    // Two zero-byte files would otherwise be linked as this page's whole stylesheet.
    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($links->fallback)->toBeTrue()
        ->and($links->header)->toBe('fallback=nothing to split')
        ->and($links->html)->toContain('sheet-1.css');
});

it('keeps no split memo for a stylesheet its driver could not take apart, so a rebuild is never served the previous split', function () use (&$scratchPaths): void {
    $driver = new class(new FlatStylesheetSplitter) extends FlatDriver
    {
        public function name(): string
        {
            return 'brittle';
        }

        public function detect(string $css): bool
        {
            return true;
        }

        public int $splits = 0;

        public function split(string $css): SplitStylesheet
        {
            $this->splits++;

            if (str_contains($css, 'V2')) {
                throw new RuntimeException('driver bug on V2');
            }

            return parent::split($css);
        }
    };
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'BW6001') && str_contains($message, 'driver bug on V2'));
    config()->set('bladewind.drivers', [$driver]);
    app()->forgetInstance(DriverSelector::class);
    $scratch = $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => 'html{color:red}.flex{display:flex}']);
    $styles = app(PageStyles::class);

    $first = $styles->forResponse('<div class="flex"></div>', []);
    expect($first->fallback)->toBeFalse();

    // vite build replaced the stylesheet under a worker that still holds the V1 split.
    file_put_contents($scratch.'/build/assets/sheet-1.css', 'html{color:blue}.flex{display:flex}.mt-2{margin-top:2px}/*V2*/');

    // The failure is memoised under V2's hash as a stylesheet nothing takes apart: every request
    // for V2 gets the full stylesheet, logged once, rather than V1's root and utilities under V2's
    // file names, and rather than a fresh attempt (and a fresh exception) per request.
    $second = $styles->forResponse('<div class="flex mt-2"></div>', []);
    $third = $styles->forResponse('<div class="flex mt-2"></div>', []);

    expect($second->fallback)->toBeTrue()
        ->and($second->header)->toBe('fallback=stylesheet not recognised')
        ->and($third->fallback)->toBeTrue()
        ->and($driver->splits)->toBe(2);

    foreach (glob(app(PageStyleStore::class)->directory().'/bw-root-*.css') ?: [] as $root) {
        expect((string) file_get_contents($root))->not->toContain('color:blue');
    }
});

it('logs BW6004 once per directory, not once per page file, when generated files cannot be written', function () use (&$scratchPaths): void {
    // Every page shape has its own file name; a directory that stays unwritable must not spend the
    // fifty-diagnostic budget on fifty file names and then fall silent for the worker's life.
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'BW6004'));

    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);
    // The root is already on disk; only the page files, each with its own name, fail to write.
    app(PageStyles::class)->forResponse('<div class="p-4"></div>', []);
    app()->instance(PageStyleStore::class, new PageStyleStore(shortWritingFilesystem(), app()->publicPath(), $this->assetsPath()));
    app()->forgetInstance(PageStyles::class);
    $styles = app(PageStyles::class);

    $first = $styles->forResponse('<div class="flex"></div>', []);
    $second = $styles->forResponse('<div class="block"></div>', []);

    expect($first->fallback)->toBeTrue()
        ->and($second->fallback)->toBeTrue()
        ->and($second->header)->toBe('fallback=page stylesheet could not be written');
});

it('names both files from the version-derived sheet id', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);
    $store = app(PageStyleStore::class);
    $hash = (string) app(PageStyles::class)->buildHash();

    // A name from the stylesheet hash alone would go on serving an old version's files after an
    // upgrade changed what belongs in them; the build hash also folds in the driver and the
    // companion stylesheets, and the sheet id folds in the package version.
    expect($hash)->not->toBe(app(StylesheetIndex::class)->hash())
        ->and(pageStylesFile($links, 'root'))->toBe('bw-root-'.$store->sheetId($hash).'.css')
        ->and(pageStylesFile($links, 'root'))->not->toBe('bw-root-'.substr($hash, 0, 12).'.css')
        ->and(pageStylesFile($links, 'page'))->toStartWith('bw-page-'.$store->sheetId($hash).'-');
});

it('writes the page CSS into a style element when delivery is inline', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(
        [
            'resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css')),
            'resources/css/extra.css' => '@layer utilities{.mt-2{margin-top:2px}}',
        ],
        ['resources/css/app.css', 'resources/css/extra.css'],
    );

    config()->set('bladewind.pages.delivery', 'inline');
    config()->set('app.debug', true);

    $links = app(PageStyles::class)->forResponse('<div class="flex border"></div>', []);
    $store = app(PageStyleStore::class);

    // The file is still written: it is the content-addressed cache prune() works from, and the
    // memo reads it back rather than the builder running twice.
    preg_match('~<style data-bladewind-page="([0-9a-f]{12})"[^>]*>(.*)</style>~s', $links->html, $style);

    expect($links->fallback)->toBeFalse()
        ->and($style)->not->toBeEmpty()
        ->and($links->html)->not->toContain('bw-page-')
        // Root first as a link, then the inlined page, then every other configured stylesheet.
        ->and($links->html)->toMatch('~^<link rel="stylesheet" href="[^"]*/bw-root-[0-9a-f]{12}\.css"><style data-bladewind-page="[0-9a-f]{12}" data-bladewind-nav="[0-9a-f]{6}">.*</style><link rel="stylesheet" href="[^"]*/build/assets/sheet-2\.css"><script src="[^"]*/bw-navigate-[0-9a-f]{12}\.js" data-bladewind-navigate defer></script>$~s')
        ->and($links->header)->toContain('delivery=inline')
        ->and($links->header)->toStartWith('page='.$style[1].' ');

    $page = 'bw-page-'.$store->sheetId((string) app(PageStyles::class)->buildHash()).'-'.$style[1].'.css';

    expect($store->has($page))->toBeTrue()
        ->and($style[2])->toBe((string) file_get_contents($store->directory().'/'.$page))
        ->and($style[2])->toContain('.flex{display:flex}');
});

it('escapes the only sequence that can end a style element early', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch([
        'resources/css/app.css' => '@layer theme{:root{--spacing:.25rem}}@layer utilities{.after-tag:after{content:"</a>"}}',
    ]);

    config()->set('bladewind.pages.delivery', 'inline');

    $links = app(PageStyles::class)->forResponse('<div class="after-tag"></div>', []);
    [, $pageCss] = [null, (string) file_get_contents(app(PageStyleStore::class)->directory().'/'.pageStylesInlineFile($links))];

    // The file keeps the CSS the browser would have fetched; only the inlined copy is escaped.
    expect($pageCss)->toContain('content:"</a>"')
        ->and($links->html)->toContain('content:"<\/a>"')
        ->and($links->html)->not->toContain('</a>')
        ->and(substr_count($links->html, '</style>'))->toBe(1);
});

it('links the page file for that response when it cannot be read back in inline mode', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    config()->set('bladewind.pages.delivery', 'inline');
    config()->set('app.debug', true);

    $styles = app(PageStyles::class);
    $store = app(PageStyleStore::class);
    $first = $styles->forResponse('<div class="flex border"></div>', []);
    $file = pageStylesInlineFile($first);
    $path = $store->directory().'/'.$file;

    // A second PageStyles instance has an empty memo, so it must read the file; make that fail.
    app()->forgetInstance(PageStyles::class);
    $fresh = app(PageStyles::class);
    chmod($path, 0);

    try {
        $links = $fresh->forResponse('<div class="flex border"></div>', []);
    } finally {
        chmod($path, 0644);
    }

    expect($links->fallback)->toBeFalse()
        ->and($links->html)->not->toContain('<style')
        ->and($links->html)->toContain('/'.$file.'"')
        ->and($links->header)->toContain('delivery=link');
})->skip(posix_geteuid() === 0, 'root can read a mode-0 file');

it('keeps at most sixty-four inlined page stylesheets in memory', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    config()->set('bladewind.pages.delivery', 'inline');
    config()->set('bladewind.pages.max_files', 1000);

    $styles = app(PageStyles::class);

    for ($i = 0; $i < 70; $i++) {
        // A distinct class token per request gives each page its own set hash and file.
        $styles->forResponse('<div class="flex case-'.$i.'"></div>', []);
    }

    $memo = (new ReflectionProperty(PageStyles::class, 'inlined'))->getValue($styles);

    expect($memo)->toBeArray()->and(count($memo))->toBeLessThanOrEqual(64)->and(count($memo))->toBeGreaterThan(0);
});

it('reads a page file once per process in inline mode', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public-tailwind/build/assets/app-tailwind.css'))]);

    config()->set('bladewind.pages.delivery', 'inline');

    $styles = app(PageStyles::class);
    $html = '<div class="flex border"></div>';
    $first = $styles->forResponse($html, []);
    $path = app(PageStyleStore::class)->directory().'/'.pageStylesInlineFile($first);

    // Rewriting the file behind the memo is the only way to tell a second read from a memo hit:
    // the file still exists, so nothing rebuilds it, and a re-read would carry the sentinel.
    file_put_contents($path, '.sentinel{color:red}');

    // The per-response marker is the one byte-level difference between two responses for one set.
    $unmarked = static fn (string $html): string => (string) preg_replace('~ data-bladewind-nav="[0-9a-f]{6}"~', '', $html);

    expect($unmarked($styles->forResponse($html, [])->html))->toBe($unmarked($first->html))
        ->not->toContain('sentinel');
});

it('links the page file when delivery is anything but inline', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => (string) file_get_contents($this->fixturePath('public/build/assets/app-test.css'))]);

    config()->set('bladewind.pages.delivery', 'somewhere-else');
    config()->set('app.debug', true);

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($links->html)->toContain('bw-page-')
        ->not->toContain('<style')
        ->and($links->header)->toContain('delivery=link');
});

it('links the whole stylesheet on the fallback path whatever the delivery mode is', function () use (&$scratchPaths): void {
    $scratchPaths[] = pageStylesScratch(['resources/css/app.css' => '@layer theme{:root{--spacing:.25rem}}.flex{display:flex}']);

    config()->set('bladewind.pages.delivery', 'inline');

    $links = app(PageStyles::class)->forResponse('<div class="flex"></div>', []);

    expect($links->fallback)->toBeTrue()
        ->and($links->header)->toBe('fallback=stylesheet not recognised')
        ->and($links->html)->toBe('<link rel="stylesheet" href="'.asset('build/assets/sheet-1.css').'">');
});
