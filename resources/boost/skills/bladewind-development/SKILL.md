---
name: bladewind-development
description: Set up, configure, debug and test BladeWind per-page CSS in a Laravel app. Use when installing daikazu/bladewind, editing config/bladewind.php, a page renders with missing styles, a log or header shows a BW diagnostic code or a fallback, writing assertPageStyles tests, or adding a custom CSS framework driver.
---

# BladeWind Development

BladeWind splits the stylesheet Vite already built into a shared root file and one file per page shape, holding only the rules that page's classes can use. It works out a page's classes from the rendered HTML plus the compile-time analysis of every view that rendered, including `@if` branches that did not render, `wire:*.class` directives and Alpine `:class` bindings. Anything it cannot handle falls back to the full stylesheet, exactly as `@vite` would.

## Setup

The layout takes the CSS entry out of `@vite` and adds the directive after it:

```blade
@vite(['resources/js/app.js'])
@bladewindStyles
```

- Add `/public/bladewind` to `.gitignore`. The web server must be able to write there.
- Publish the config only to change a default: `php artisan vendor:publish --tag=bladewind-config`.
- Page styles need a production build (`npm run build`). Under `npm run dev` every page falls back.

## Declaring classes BladeWind cannot see

The analysis sees Blade source and rendered HTML only. For anything else, edit `config/bladewind.php`:

```php
'safelist' => [
    'prose',          // exact token, on every page
    'chart-*',        // prefix pattern, expanded against the stylesheet's classes
],

'components' => [
    'dashboard' => [                         // component name or view name
        'classes' => ['bg-red-500', 'hidden'], // tokens the view adds at runtime
    ],
    'pages.show' => [
        'dynamic' => ['alert', 'banner'],      // allowed targets of <x-dynamic-component>
    ],
],

'pages' => [
    'keep_variables' => ['--color-brand'],   // CSS variables JavaScript reads by name
],
```

Prefer rewriting a runtime-built class name (`"text-{$size}"`) as a choice between full names over safelisting it.

Declare classes under `components` for:

- a Livewire child that does not render on the first request (`lazy`, inside a false `@if`, opened by an event)
- a class-based Blade component rendered only after an update

## Debugging a page

1. Set `BLADEWIND_DEBUG=true` in `.env`. Each page gets a metrics panel, and each response gets an `X-BladeWind-Styles` header, such as `page=3f80f967cdda framework=tailwind4 tokens=80 analysed=9 unanalysed=1 ...`, or `fallback=<reason>`.
2. Set `pages.enabled` to `false` and reload. If the page is right with the full stylesheet, a class is missing from the page's set: find its source and declare it.
3. Run `php artisan bladewind:inspect <view>` to see what a view produces: its classes, its dependencies and its diagnostics. Run `php artisan bladewind:analyze` for every view.
4. Run `php artisan bladewind:clear` to delete generated files. They regenerate on the next request.

Common codes:

- BW2001: a class expression cannot be enumerated. Write full names or safelist them.
- BW6001: the page fell back. The message says why.
- BW6003: a rendered view sits outside `paths`, so the page got the full stylesheet. Add its directory to `paths`.
- BW6004: a file could not be written. Make `public/bladewind` writable.

The full list is in the package's `docs/diagnostics.md`.

## Testing pages

```php
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Testing\AssertsPageStyles;
use Daikazu\BladeWind\Testing\PageExpectation;

uses(AssertsPageStyles::class);

beforeEach(fn () => app(PageStyleStore::class)->clear());

it('serves the checkout page every rule it can need', function () {
    $this->assertPageStyles('/checkout', new PageExpectation(
        reaches: ['opacity-50'],  // classes a Livewire update or Alpine binding can add
        absent: ['prose'],        // proves the page file is per page
        diagnostics: [],          // exact: an unlisted code fails, a listed code that stops firing fails
    ));
});
```

- The suite needs a built stylesheet under `public/build`. The trait never builds one.
- Other options: `fallback: true`, `unanalysed: n` and `framework: 'tailwind4'`.

## Custom framework driver

For a stylesheet with no cascade layers, extend `FlatDriver` and register the class in `drivers`:

```php
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

- Override `runtimeTokens()` to return classes the framework's own JavaScript adds.
- For layered output, implement `CssFrameworkDriver` directly. `Tailwind4Driver` is the worked example.
- A page must never lack a rule or a variable it needs, so when in doubt, carry more.
