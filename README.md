![BladeWind](https://raw.githubusercontent.com/daikazu/bladewind/main/art/bladewind-header.png)

# BladeWind

[![Latest Version on Packagist](https://img.shields.io/packagist/v/daikazu/bladewind.svg?style=flat-square)](https://packagist.org/packages/daikazu/bladewind)
[![Tests](https://img.shields.io/github/actions/workflow/status/daikazu/bladewind/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/daikazu/bladewind/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/daikazu/bladewind/php.svg?style=flat-square)](https://packagist.org/packages/daikazu/bladewind)
[![Total Downloads](https://img.shields.io/packagist/dt/daikazu/bladewind.svg?style=flat-square)](https://packagist.org/packages/daikazu/bladewind)
[![License](https://img.shields.io/packagist/l/daikazu/bladewind.svg?style=flat-square)](LICENSE.md)

Per-page CSS for Laravel apps built with Blade.

You know the Lighthouse warning. "Reduce unused CSS," it says, while every page on your site pulls down the same big compiled `app.css`, full of rules for classes that page never shows. I got tired of seeing it, so I built BladeWind.

BladeWind gives each page a small shared stylesheet plus a second one holding only the rules that page can use. It writes that file on the first visit to a page and reuses it until the page or your CSS changes. You add two lines to your layout. You don't add a build step, and your server never runs Node.

It started as a fix for Tailwind. Along the way it turned out to work with Tachyons, Bootstrap, Bulma and Foundation too.

> [!NOTE]
> **This is an early release.** Tailwind is where BladeWind has seen the most use. The other frameworks pass the test suite and work on the test sites, but they haven't met many real projects yet. If you try BladeWind with any of them, I'd love to hear how it went, good or bad: [open an issue](https://github.com/daikazu/bladewind/issues) with your framework and what you saw. Until 1.0, a minor release may include breaking changes, and the [changelog](CHANGELOG.md) will call them out.

## What it does

- **Splits, never compiles.** BladeWind reads the stylesheet your `vite build` already produced and cuts it into a root file and per-page files, in PHP, at request time. No Node, no Tailwind, no Sass on the server; no build step added to yours.
- **Copies rules verbatim, in order.** A page file is the original rules, byte for byte, in their original order, so specificity and cascade are exactly what your framework produced. The test suite checks, for every supported framework, that each rule and variable a page needs is reachable.
- **Knows what a page can become.** The class set for a page is the classes in the rendered HTML, plus every class any rendered view could produce later: `@if` branches that did not render, `wire:loading.class`, Alpine `:class` bindings, and, for Bootstrap and Foundation, the classes their own JavaScript adds. A Livewire update never arrives without its CSS.
- **Fails toward the full stylesheet.** Anything it cannot handle, from the Vite dev server running to a stylesheet shape it does not recognise, links your normal stylesheet exactly as `@vite` would and logs why, once.

Supported frameworks: Tailwind CSS 4 and 3, Tachyons, Bootstrap 5, Bulma 1 and Foundation for Sites 6, each recognised from the compiled stylesheet. Livewire 4, Alpine and livewire/blaze are supported when present.

What it does not do: it does not purge, minify or rewrite your CSS; it does not touch your views, your compiled views or your HTML beyond the stylesheet links; and it cannot see classes that arrive from outside Blade (see [When to use it, and when not to](#when-to-use-it-and-when-not-to)).

### How much you save

I measured the gzipped CSS a page loads on the test sites I built BladeWind against, one per framework. Your numbers depend on how much of your framework is per-page rules and how much is base styling every page needs anyway:

| Framework | Full stylesheet | With BladeWind | Saving |
|---|---|---|---|
| Tailwind CSS 4 | 16.6 KB | 6.1 to 7.7 KB | 54 to 63% |
| Tailwind CSS 3 | 10.1 KB | 5.1 KB | 50% |
| Tachyons | 13.6 KB | 2.4 KB | 83% |
| Bootstrap 5 | 30.7 KB | 10.1 KB | 67% |
| Bulma 1 | 65.0 KB | 34.6 KB | 47% |
| Foundation 6 | 17.4 KB | 10.7 KB | 38% |

Tailwind 4 pages also get their theme variables and `--tw-*` defaults shaken per page; the other frameworks keep their variables and resets whole, since those come before the first component rule or must reach every page.

## Requirements

PHP 8.4 and Laravel 13. The stylesheet must be built through Vite, so it can be found through `public/build/manifest.json`, and be the output of Tailwind CSS 4 or 3, Tachyons, Bootstrap 5, Bulma 1 or Foundation for Sites 6 (or a layer-less utility stylesheet you name as `tailwind3` or `tachyons` in `framework`). The web server must be able to write to `public/bladewind`. Livewire 4 and livewire/blaze 1 are supported when present; neither is required.

## Installation

1. Install the package:

   ```bash
   composer require daikazu/bladewind
   ```

2. In your layout, take the CSS entry out of `@vite` and add the directive right after it:

   ```blade
   @vite(['resources/js/app.js'])
   @bladewindStyles
   ```

3. Add the generated-files directory to `.gitignore`:

   ```gitignore
   /public/bladewind
   ```

That's it. Run `npm run build` like always, and the first visit to each page writes its files. The web server needs write access to `public/bladewind`.

Publish the config only if you want to change a default:

```bash
php artisan vendor:publish --tag=bladewind-config
```

## Seeing it work

Build your assets, load a page, and check any of these:

- View the page source: the head links `bw-root-<id>.css` and `bw-page-<id>-<set>.css` instead of `app-<hash>.css`.
- With `BLADEWIND_DEBUG=true` in `.env`, every page gets a small panel in the corner. Collapsed, it shows a ring and the figures: the CSS this page loaded against the full stylesheet, and the saving. Expanded, it lists the shared root, this page's file and the full stylesheet with a bar, then how the response was built: whether the page stylesheet was reused or generated and how long BladeWind took, how it was delivered, and which framework driver split the stylesheet. A "Check" row appears only when there is something to act on, such as rendered views outside `paths`. The class and view counts stay in the `X-BladeWind-Styles` header. The panel is plain HTML plus one `<style>` element scoped to itself that carries your Vite CSP nonce; it has no class attribute and no script.
- With `APP_DEBUG=true` (or the package debug mode), every response carries an `X-BladeWind-Styles` header, for example `page=3f80f967cdda framework=tailwind4 tokens=80 analysed=9 unanalysed=1 support=42 delivery=link root=4475 page=7239`, or `fallback=<reason>` when the full stylesheet was served instead. `analysed`/`unanalysed` count the rendered views with and without an analysis entry.
- `public/bladewind/` holds the files. `php artisan bladewind:clear` deletes them; they come back on the next request.
- Compare styles: set `pages.enabled` to `false`, reload, and the page should look identical with the full stylesheet.

## When to use it, and when not to

BladeWind earns its keep on sites with lots of different pages that each use a slice of the stylesheet: a marketing site with a dashboard bolted on, a content site with varied templates, a component framework where every page pays for every component. It does little for a single dashboard app where every page uses most of the CSS, or for a stylesheet that's already tiny.

Skip it, or plan to configure around it, when:

- **Classes arrive from outside Blade.** BladeWind sees Blade views and the rendered HTML. Markup rendered by client-side JavaScript (a React or Vue island, a chart library, a rich-text editor's output), classes stored in the database (CMS content, Markdown converted with classes), and classes your own JavaScript adds are invisible to the analysis. If the class is in the rendered HTML it is covered; if it appears only after the page loads, it is not, and the rule is missing on that page. The `safelist` and `components` settings exist for exactly this, and the frameworks whose own JavaScript adds classes (Bootstrap, Foundation) already have those declared by their driver.
- **Class names are built at runtime.** `"bg-{$color}-500"` cannot be enumerated (BW2001). Either write the full class names out and pick between them, or safelist them.
- **The web server cannot write to `public`.** Files are written on first request. A read-only container image, an immutable deploy, or a `public` directory served from somewhere the PHP process cannot write to means every page falls back to the full stylesheet, with BW6004 in the log. `assets.path` can point elsewhere under `public`, but it has to be writable and served.
- **A push CDN serves `public`.** Files written at runtime never reach a CDN fed from your build. A pull CDN in front of your origin is fine (`assets.url`).
- **Vite runs in development mode.** With `npm run dev` the stylesheet is served from memory and there is nothing to split, so pages fall back. Run `npm run build` to see page styles locally.
- **You need a byte-identical stylesheet URL across pages.** Each page shape has its own file, so a site with thousands of distinct shapes writes thousands of small files (bounded by `pages.max_files`) and a first visit to a new shape pays a few milliseconds to generate it.

Framework-specific limits worth knowing before choosing:

- Only Tailwind 4 has a theme layer to shake per page; the others keep every variable and the whole reset in the root or in every page. Bulma's two theme rules (light and dark variable sets) are about 100 KB raw and travel with every page. Foundation's build opens with a component rule ahead of normalize, so its root is nearly empty and normalize rides in every page file.
- Variant rules are indexed by the class on the element they style, so a page carrying `class="dark"`, `class="group"` or Tachyons' `hide-child` does not receive every variant rule in the stylesheet, only the ones its own utilities select.
- Only the first entry in `stylesheets` is split; the others are linked whole.

## How it works

You don't need this section to use BladeWind. If you want to know what happens to a request, it goes through six steps:

1. **Analysis at compile time.** When Blade compiles a view, BladeWind parses it and records every class token the view can produce: static `class` attributes, `@class` arrays, PHP expressions it can enumerate, `wire:loading.class`, Alpine `:class` bindings, and which views and components it includes. The entry is stored beside the compiled view. Nothing is precomputed for the whole site, and no command has to run first.
2. **Marked links.** `@bladewindStyles` in your layout renders your normal stylesheet links, marked for replacement, so the page is complete even if nothing else happens.
3. **The page's class set.** When the response is ready, a global middleware unions the classes in the final HTML with the analysed inventory of every view that rendered and everything those views statically include. Custom properties the document reads in `style` attributes, and the ones `pages.keep_variables` names, join the set as pseudo-tokens. So do the framework's runtime classes.
4. **The split.** The compiled stylesheet is taken apart once per build by the driver that recognises it, and the result is memoised per process:
   - **Tailwind 4** keeps its utilities in `@layer utilities` and its theme in `@layer theme`, so the root is the base and components layers plus every `@keyframes`, and a page file is its utilities, wrapped in the same layer, with only the theme variables, `--tw-*` defaults and `@property` registrations those utilities reach.
   - **Every other framework** is a flat stylesheet, so the split is a cut: everything before the first rule with a class selector (the reset or preflight, `--tw-*`, `--bs-*` or `--bulma-*` variables, your base styles) is the root, and everything from that rule on is what pages pick from, in source order, with `@keyframes` lifted into the root. Rules whose selectors name no class, or name an element beside a class, reach every page, because no class set can say whether an element needs them.
5. **The files.** `bw-root-<id>.css` and `bw-page-<id>-<set>.css` are written under `public/bladewind` on first use, content-addressed, and pruned by least recent use. The marked links are replaced with links to them, or with an inline `<style>` for the page part.
6. **The fallback.** Anything unexpected links the full stylesheet as `@vite` would.

Every step runs in PHP against the compiled stylesheet found through the Vite manifest. Build wherever you build today, ship `public/build` as usual, and make sure the web server can write to `public/bladewind`.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Turns the whole package off when `false`; `@bladewindStyles` then emits your normal links. |
| `debug` | `false` | Adds the metrics panel to every page and sends the `X-BladeWind-Styles` header. For development only. |
| `paths` | `[resource_path('views')]` | Directories whose views are analysed. |
| `pages.enabled` | `true` | Per-page stylesheets. `false` links the full stylesheet. |
| `pages.delivery` | `link` | `link` links the page file. `inline` writes it into the HTML as a `<style>` element instead: no flash of unstyled content with `wire:navigate` and one less request, at the cost of the page CSS (1 to 2 KB gzipped) travelling with every response instead of being cached per URL. |
| `pages.keep_variables` | `[]` | Theme variables your JavaScript reads by name (`getPropertyValue('--color-brand')`), which the server cannot see. Exact names or `--prefix-*`. Variables used in `style` attributes, `:style` bindings or `<style>` blocks are found automatically. |
| `pages.max_files` | `500` | Page files kept per compiled stylesheet before the least recently used are pruned. Keep it above your number of distinct page shapes. |
| `pages.unanalysed` | `fallback` | What a page gets when one of its rendered views has no analysis entry (a package view outside `paths`): `fallback` links the full stylesheet and logs BW6003 naming the view; `html` builds the page from the classes that rendered, which misses branches that view shows only after a Livewire update. |
| `assets.path` | `bladewind` | Directory under `public/` for generated files. Keep it outside Vite's build directory, which `vite build` empties. |
| `assets.url` | `null` | Base URL for generated files. Leave `null` unless a CDN pulls from your origin; a push CDN never receives files written at runtime. |
| `stylesheets` | `['resources/css/app.css']` | Your Vite CSS entries. The first is split into root and page files. Each must be a CSS entry of the manifest: a script entry that imports CSS is refused (BW3002), and an entry the manifest does not carry makes `@bladewindStyles` throw exactly as `@vite` would. |
| `build_directory` | `build` | Vite's build directory under `public/`, if the application calls `Vite::useBuildDirectory()`. |
| `drivers` | `[]` | Your own driver classes, tried before the built-in ones under `auto` and selectable by name. See "Writing your own driver". |
| `framework` | `auto` | Whose output the stylesheet is split as: `tailwind4`, `tailwind3`, `tachyons`, `bootstrap5`, `bulma`, `foundation`, or `auto` to recognise it from the stylesheet (a top-level `@layer utilities` block, else the `--tw-*` namespace, else the Tachyons banner, else the `--bs-*` or `--bulma-*` namespace, else Foundation's reveal overlay and XY grid classes). Name one to skip detection, or to split a layer-less utility stylesheet that is none of these as `tailwind3` or `tachyons`, which split the same way. |
| `safelist` | `[]` | Class tokens every page carries whatever the analysis sees: exact tokens, or `prefix-*` patterns expanded against the classes the stylesheet has rules for. |
| `components` | `[]` | Per component or view: `classes` a view can produce that analysis cannot see; `dynamic` allowed targets of a dynamic component. |
| `cache_path` | `null` | Where analysis entries live. `null` means `storage/framework/views/bladewind`, which `view:clear` removes. |

## Troubleshooting

Every `BW` code mentioned below is explained in [docs/diagnostics.md](docs/diagnostics.md).

- **A page looks wrong on one page only.** That is the failure mode to watch for: a rule missing from that page's file. Check whether the class comes from somewhere Blade cannot see (JavaScript, the database, a runtime-built name) and safelist it. Set `pages.enabled` to `false` and reload: if the page is right with the full stylesheet, the class set is what to fix.
- **A page gets the full stylesheet and the log says BW6003.** One of its rendered views sits outside `paths` (a package's own Blade, such as Livewire's pagination views), so its hidden branches are unknown and the page is served whole rather than guessed at. Add that directory to `paths` and the page gets its own file again.
- **Fallback.** The full stylesheet is linked when Vite is running hot (`npm run dev`), when the Vite manifest or stylesheet cannot be read, when no framework driver recognises the stylesheet or the chosen one finds nothing to split (BW6002 says what was looked for), when a generated file cannot be written (BW6004), or when anything throws. Each reason is logged once per process (BW6001) and shown in the debug panel. What never falls back is configuration: a `stylesheets` entry the manifest cannot resolve or a missing manifest makes `@bladewindStyles` throw exactly as `@vite` would, and a `drivers` entry that is not a driver or an `assets.path` that is empty or leaves `public/` is refused when the application boots, since a page with no stylesheet is not a fallback. If a minifier or a test with `withoutMiddleware()` stops the replacement, the page simply keeps the normal links the directive wrote.
- **Views outside `paths`.** Package views under `vendor/` have no analysis entry, so a page that renders one links the full stylesheet (`pages.unanalysed`). Strings rendered through `Blade::render()` have no source beyond their HTML and are covered by it. Published package views under `resources/views/vendor` are analysed like any other. Add a directory to `paths` to bring its views into the analysis.
- **Runtime classes.** The page set covers classes any rendered view can produce, so a Livewire update that flips an `@if` branch or applies `wire:loading.class` has its CSS. A component that never rendered on the page is not covered; declare its classes under `components`.
- **Upgrades and deploys.** No `view:clear` is needed. Entries from an older BladeWind version, or missing after a deploy, are rebuilt during the request that needs them. Generated file names include the package version, so an upgrade names new files and prunes the old ones.
- **Content Security Policy.** Register your nonce with `Vite::useCspNonce()` and every generated tag carries it. Without a nonce, a policy that forbids inline styles blocks `inline` delivery, so keep `link`.
- **A generated file is missing.** The web server serves `public/bladewind/*` straight from disk. When a file is not there (another server wrote it and `public/` is not shared, a deploy or the `pages.max_files` prune removed it after a cached page or a browser had linked it), a route under `assets.path` answers with the full stylesheet, uncached, so the page is styled and the miss never sticks. More than one web server therefore works without shared storage, at the cost of the full stylesheet on the misses; share `public/bladewind` or point `assets.url` at one origin to avoid them.
- **`wire:navigate`.** With `link` delivery, Livewire loads the next page's stylesheet without waiting for it, so a page with a different class set can briefly paint before its utilities arrive. `inline` delivery removes that. Either way, Livewire keeps every stylesheet it has seen in the head, so the page asset carries a per-response marker that makes Livewire append the current page's asset last on every navigation (a page visited earlier would otherwise sit behind the pages visited since, and their copies of shared base rules would override its later ones), and a one-line script removes the stale page assets after each navigation. The script is inline, carrying your nonce, when `Vite::useCspNonce()` was called, and otherwise an external file served by the package (`bw-navigate-*.js` under `assets.path`), so a `script-src 'self'` policy runs it.
- **Theme values it cannot split.** A Tailwind 4 theme or properties layer in a shape this version does not recognise is kept whole in the root and BW6005 says so; pages still work, just with a larger root.
- **JavaScript reading theme variables.** `getComputedStyle(...).getPropertyValue('--color-brand')` is invisible to the server, so name such variables in `pages.keep_variables`. Variables used in `style` attributes and `<style>` blocks are found automatically. Animations are never a problem: every `@keyframes` rule is in the root, so a `style="animation: spin 1s"` set from JavaScript works on every page.
- **Long-running workers.** Under Octane the split, the variable graph and the rule index are memoised per process and rebuilt only when the stylesheet's hash changes after a build. Diagnostics are logged once per process, so a recurring fallback costs one log line.
- **Disk usage.** Page files are content-addressed and pruned by least recent use above `pages.max_files` per compiled stylesheet. A rebuild names new files and prunes the old ones after a grace period; `php artisan bladewind:clear` removes everything and the next request regenerates it.

## Livewire, Alpine and Blaze

- Livewire class components, single-file and multi-file components are analysed; `wire:*` attributes and morph markers are never touched. `wire:loading.class`, `wire:dirty.class` and the other `.class` directives are recorded, and so is `wire:current`, whose classes Livewire adds on the client and which are therefore never in the rendered HTML.
- A Livewire child that is not rendered on the first request (`lazy`, mounted inside a false `@if`, opened by an event) has no static path from its parent: its classes reach the page only if a rendered view carries them, so declare them under `components` for the view that renders the page. The same holds for a class-based Blade component (`App\View\Components\*`) rendered only after an update; anonymous components are followed statically.
- Alpine `x-bind:class` and `:class` values are enumerated where they are static expressions; unresolvable ones record their candidate tokens.
- Blaze compile, memo and fold strategies all work; folded components are covered through their parent's dependency closure.

## Commands

```bash
php artisan bladewind:analyze            # analyse every view and print a summary (optional; entries are also written lazily)
php artisan bladewind:analyze --json     # the same as JSON; --path=dir limits the scan
php artisan bladewind:inspect <target>   # what one view produces: classes, dependencies, diagnostics (--json available)
php artisan bladewind:clear              # remove entries and generated files
```

None of them is required for page styles to work.

## Testing your pages

The package ships a trait for a host application's own suite. One call per page asserts it was served with page styles rather than the fallback, that every class in its HTML the stylesheet styles has a rule in the root or page file, that the classes you name as reachable at runtime have one too, and that the page produced exactly the diagnostics you expect.

```php
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Testing\AssertsPageStyles;
use Daikazu\BladeWind\Testing\PageExpectation;

uses(AssertsPageStyles::class);

beforeEach(fn () => app(PageStyleStore::class)->clear());

it('serves the checkout page every rule it can need', function () {
    $this->assertPageStyles('/checkout', new PageExpectation(
        reaches: ['opacity-50', 'border-red-500'],   // wire:loading.class, an @error branch
        absent: ['prose'],                            // used only on the blog
        diagnostics: [],                              // any BW code fails the test
    ));
});
```

`reaches` is for classes the rendered HTML does not carry but a Livewire update or an Alpine binding can add. `absent` proves the page file is really per page. `diagnostics` is exact: a code you did not list fails, and a listed code that stopped firing fails too. `fallback: true` asserts the page falls back, `unanalysed: n` pins the header's count of rendered views without an analysis entry (left unchecked by default, since a dynamic component or a Livewire island counts its string-compiled views there), and `framework: 'tailwind4'` pins the driver. The call returns what it served (`rootCss`, `pageCss`, `fullCss`, the header fields) for assertions of your own. The suite needs a built stylesheet under `public/build`; the trait never builds one.

The call turns `bladewind.debug` on for the rest of the test, so the response carries the header. Clear the generated files between tests with `app(PageStyleStore::class)->clear()` in a `beforeEach` so each page is generated fresh. The HTML check is a differential check of the package's own scanner and split: rendered classes are always in a page's set, so regressions in analysis — unrendered branches, Alpine bindings, Livewire classes — are what `reaches` catches. Request-time codes (BW3xxx, BW6xxx) are logged once per process per reason, so pin them on the first page that triggers them in a test.

## Writing your own driver

A driver tells BladeWind how one framework's compiled stylesheet is shaped. For a framework that keeps its rules in no cascade layer, which is every one except Tailwind 4, that is a name and a way to recognise the stylesheet:

```php
namespace App\Css;

use Daikazu\BladeWind\Pages\Drivers\FlatDriver;

final class UnoCssDriver extends FlatDriver
{
    public function name(): string
    {
        return 'unocss';
    }

    public function detect(string $css): bool
    {
        return str_contains($css, '--un-');
    }
}
```

Register it in `config/bladewind.php`:

```php
'drivers' => [App\Css\UnoCssDriver::class],
```

That is all. `FlatDriver` supplies the rest: the split (everything before the first rule with a class selector is the root, the rest is what pages pick from, `@keyframes` lifted into the root), the indexing of each rule under the classes of the element it styles, the bare page file, and the BW6002 wording. Your driver is tried before the built-in ones under `framework => 'auto'`, and `framework => 'unocss'` selects it outright.

Override `runtimeTokens()` when the framework's own JavaScript puts classes on elements no view contains (the way Bootstrap creates `modal-backdrop` or Foundation `reveal-overlay`): return those class names and every page carries their rules.

For a framework whose output is layered, or whose variables should be shaken per page, implement `Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver` directly and return a `SplitStylesheet`; `Tailwind4Driver` and `StylesheetSplitter` are the worked example. Whatever you return, the rule the package holds itself to applies: a page must never lack a rule or a variable it needs, so err toward carrying more, and run your stylesheet through the pattern in `tests/Unit/Pages/Drivers/` to check that every rule after the cut is reachable from a token or unconditional.

## Diagnostics

Everything BladeWind cannot see or do is reported with a stable `BW` code: analysis codes (BW1xxx, BW2xxx) from `bladewind:analyze` and `bladewind:inspect`, and request-time codes (BW3xxx, BW6xxx) in the application log. None of them breaks a page. The full list, with what each means and what to do about it, is in [docs/diagnostics.md](docs/diagnostics.md).

## Resources

- [Diagnostics reference](docs/diagnostics.md): every `BW` code, what it means and what to do about it.
- [Changelog](CHANGELOG.md): what changed in each release.
- [Issues](https://github.com/daikazu/bladewind/issues): bug reports and feature requests. If a page looks wrong with BladeWind and right without it, include the framework and the class that went missing.
- [Laravel Boost](https://laravel.com/docs/boost): BladeWind ships a Boost guideline and a `bladewind-development` skill, so your coding agent knows not to build class names at runtime. `php artisan boost:install` picks them up, or `php artisan boost:update --discover` in an app that already uses Boost.
- [Laravel's Vite documentation](https://laravel.com/docs/vite): the build BladeWind reads from.
- [Lighthouse: Reduce unused CSS](https://developer.chrome.com/docs/lighthouse/performance/unused-css-rules): the audit that started all this.

## Credits

- [Mike Wall](https://github.com/daikazu), who wrote it.
- [John Koster](https://github.com/JohnathonKoster) for [Forte](https://github.com/fortephp/forte), the fault-tolerant Blade parser BladeWind's analysis is built on.
- [All contributors](https://github.com/daikazu/bladewind/contributors).

## License

BladeWind is open-source software licensed under the [MIT license](LICENSE.md).
