<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, BladeWind neither observes Blade compiles nor serves page
    | styles, and @bladewindStyles links the full stylesheet. The artisan
    | commands remain available.
    |
    */

    'enabled' => env('BLADEWIND_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Debug mode
    |--------------------------------------------------------------------------
    |
    | Sends the X-BladeWind-Styles header on every page and adds a small
    | metrics panel to the page: what was delivered, how it compares to the
    | full stylesheet, and whether the page file was cached or generated now.
    | Independent of app.debug (which only sends the header).
    |
    */
    'debug' => env('BLADEWIND_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Directories scanned by bladewind:analyze and watched by the compile
    | observer. Files outside these directories are ignored.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache path
    |--------------------------------------------------------------------------
    |
    | Where per-view entries and the aggregate report are stored. Null means
    | config('view.compiled') . '/bladewind', which view:clear removes.
    |
    */

    'cache_path' => null,

    /*
    |--------------------------------------------------------------------------
    | Safelist
    |--------------------------------------------------------------------------
    |
    | Class tokens to keep regardless of analysis. Each entry is an exact token
    | ("bg-red-500") or a prefix ending in a single "*" ("text-*").
    |
    */

    'safelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Component declarations
    |--------------------------------------------------------------------------
    |
    | Keyed by a component name as written in a tag ("badge", "forms.input") or
    | a logical view name ("pages.about").
    |   'classes' => tokens the view uses at runtime that analysis cannot see.
    |   'dynamic' => allowed targets for an <x-dynamic-component> in that view.
    |
    */

    'components' => [
        // 'badge' => ['classes' => ['bg-red-500', 'bg-green-500'], 'dynamic' => []],
    ],

    /*
    |--------------------------------------------------------------------------
    | Page styles
    |--------------------------------------------------------------------------
    |
    | When enabled, `@bladewindStyles` links a root stylesheet plus a per-page
    | stylesheet holding only the utilities the page can use, generated on the
    | first request that needs it and cached by content under `assets.path`.
    | `max_files` caps the number of page stylesheets kept per compiled
    | stylesheet (oldest pruned first). Pages fall back to the full stylesheet
    | whenever their class set cannot be established.
    |
    | delivery decides how the page's own CSS reaches the browser. "link" (the
    | default) links the generated page stylesheet, so the browser caches it per
    | URL. "inline" writes it into the HTML as a <style> element instead. That
    | saves a request on a cold visit and removes the wire:navigate flash
    | (Livewire does not wait for a newly appended stylesheet link, so a page
    | needing new utilities can render one frame without them). The cost is that
    | the page CSS (a kilobyte or two) travels with every HTML response. The root
    | stylesheet stays a link either way. Under a Content Security Policy,
    | register the nonce with Vite::useCspNonce() and every generated tag carries
    | it; without one, a policy that forbids inline styles blocks the <style>
    | element, so keep "link".
    |
    | unanalysed decides what happens when a rendered view has no analysis entry
    | because it sits outside `paths` (a package's own Blade views). Its `@else`
    | branches and runtime classes are unknown, so "fallback" (the default) serves
    | that page the full stylesheet and logs BW6003 naming the view: add its
    | directory to `paths` to get page styles back. "html" builds the page from
    | the classes that rendered, which is right until the first Livewire update
    | reveals a branch the analysis never saw.
    |
    | keep_variables names theme variables that JavaScript reads by name, which
    | the server cannot see (getPropertyValue('--color-brand'), a chart library
    | fed from a CSS custom property). Each one is defined on every page whether
    | or not its CSS or markup mentions it. Entries are exact names
    | ('--color-brand') or a prefix pattern ('--color-brand-*').
    |
    */

    'pages' => [
        'enabled' => true,
        'max_files' => 500,
        'delivery' => 'link',
        'unanalysed' => 'fallback',
        'keep_variables' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | CSS framework
    |--------------------------------------------------------------------------
    |
    | Which framework's compiled output the primary stylesheet is taken apart
    | as. "auto" (the default) recognises it from the stylesheet itself; name
    | one to skip detection or to split output the signature check misses.
    |
    |   tailwind4  Tailwind CSS 4: cascade layers, theme variables, @property
    |   tailwind3  Tailwind CSS 3: flat output with the --tw-* defaults
    |   tachyons   Tachyons: flat output, no custom properties
    |   bootstrap5 Bootstrap 5: flat output, --bs-* variables, and the classes
    |              its JavaScript adds carried on every page
    |   bulma      Bulma 1: flat output, --bulma-* variables
    |   foundation Foundation for Sites 6: flat output, and the classes its
    |              JavaScript adds carried on every page
    |
    | The layer-less drivers split the same way (everything before the first
    | class rule is the root), so tailwind3 or tachyons works for a flat
    | utility stylesheet that is neither.
    |
    */

    'framework' => env('BLADEWIND_FRAMEWORK', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Your own drivers
    |--------------------------------------------------------------------------
    |
    | Classes implementing Daikazu\BladeWind\Pages\Drivers\CssFrameworkDriver
    | for a framework the package does not know. A layer-less framework needs
    | only a name and a detection signature: extend FlatDriver. Listed drivers
    | are tried before the built-in ones under "auto" and can be named in
    | "framework".
    |
    */

    'drivers' => [
        // App\Css\UnoCssDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stylesheets
    |--------------------------------------------------------------------------
    |
    | Vite entry names of the compiled stylesheets page styles are built from,
    | resolved through public/build/manifest.json. The first one that resolves
    | is split into root and page files; the others are linked whole.
    |
    */

    'stylesheets' => ['resources/css/app.css'],

    /*
    |--------------------------------------------------------------------------
    | Build directory
    |--------------------------------------------------------------------------
    |
    | Vite's build directory under public/, where the manifest and the compiled
    | stylesheets are read from. Change it only if the application calls
    | Vite::useBuildDirectory(), which Laravel offers no way to read back.
    |
    */

    'build_directory' => 'build',

    /*
    |--------------------------------------------------------------------------
    | Generated assets
    |--------------------------------------------------------------------------
    |
    | path: directory, relative to the public path, that receives the files
    |       BladeWind generates (the root and page stylesheets written while
    |       serving requests). Keep it outside Vite's build directory: `vite
    |       build` empties that directory, and the files would go with it. Add
    |       it to .gitignore.
    | url:  base URL those files are linked by. Null serves them from the
    |       application's own origin, which is where they are written. Set it
    |       only for a CDN that pulls from your origin: a push CDN fed by your
    |       build (an ASSET_URL bucket) never receives files written at
    |       runtime, and every page would link a 404.
    |
    */

    'assets' => [
        'path' => 'bladewind',
        'url' => null,
    ],

];
