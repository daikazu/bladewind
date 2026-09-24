# Diagnostics

BladeWind reports what it could not see or do as diagnostics, each with a stable `BW` code. None of them breaks a page: the worst outcome is that a page links the full stylesheet, exactly as `@vite` would.

## Where they appear

- **Analysis codes (BW1xxx, BW2xxx)** are recorded when Blade compiles a view. See them with `php artisan bladewind:analyze` for every view, or `php artisan bladewind:inspect <view>` for one. BW1008 (analysis failed) is also written to the log.
- **Request-time codes (BW3xxx, BW6xxx)** are written to the application log, once per process per reason, so a recurring problem costs one log line. With debug on, the `X-BladeWind-Styles` header and the debug panel show the current page's fallback reason.
- **In tests**, `assertPageStyles()` compares the codes a page produced with the `diagnostics` list you pass. See "Testing your pages" in the [README](../README.md#testing-your-pages).

## Analysis: views and dependencies (BW1xxx)

| Code | Severity | Meaning | What to do |
|---|---|---|---|
| BW1001 | warning | A directive's target (`@include($name)`) is not a string literal | Use a literal view name so the included view is tracked |
| BW1002 | warning | A referenced view was not found in any view path | Fix the view name, or ignore it if the branch is dead code |
| BW1003 | warning | A component tag could not be resolved to a class or a view | Check the component name and its namespace registration |
| BW1004 | info | A dynamic component's target is not statically known | List the allowed targets under `components.<view>.dynamic` |
| BW1005 | info | A class component's rendered view is not statically known | Declare the classes it can produce under `components` |
| BW1006 | info | A `paths` entry does not exist and was skipped by `bladewind:analyze` | Fix or remove the entry |
| BW1008 | error | Analysis failed for a file | Read the logged exception; the view still compiles and renders normally |

## Analysis: classes (BW2xxx)

| Code | Severity | Meaning | What to do |
|---|---|---|---|
| BW2001 | info | A class expression cannot be enumerated (`"bg-{$color}-500"`) | Write full class names and choose between them, or add them to `safelist` |
| BW2002 | info | An Alpine binding is not enumerable; candidate tokens were recorded | Declare any classes it can produce that are not among the candidates |
| BW2003 | info | A Livewire `.class` directive has a dynamic value | Declare the classes it can produce under `components` |
| BW2004 | warning | An `@class` or helper element is not a string or a `'classes' => condition` pair, and was skipped | Rewrite the element in one of those two forms |
| BW2005 | info | `bladewind:analyze` found views that include each other in a cycle | Usually nothing; it is informational |
| BW2006 | warning | A key in `components` does not resolve to a view or an anonymous component | Fix the key; the declaration is ignored until it resolves |
| BW2007 | warning | A `safelist` entry is not an exact token or a `prefix-*` pattern | Fix the entry; it is ignored as written |

## Stylesheets (BW3xxx)

| Code | Severity | Meaning | What to do |
|---|---|---|---|
| BW3001 | warning | A stylesheet was not found through the Vite manifest | Run `npm run build` and check `stylesheets` and `build_directory` |
| BW3002 | warning | A `stylesheets` entry resolves to a script, not a stylesheet | Name the CSS entry itself (`resources/css/app.css`), not the script that imports it |

## Page styles (BW6xxx)

| Code | Severity | Meaning | What to do |
|---|---|---|---|
| BW6001 | warning | Page styles fell back to the full stylesheet | Read the reason in the message. Vite running hot (`npm run dev`) is the common one and expected |
| BW6002 | warning | The stylesheet cannot be split: no driver recognised it, or the chosen driver found nothing to split (no top-level `@layer utilities` block for Tailwind 4, no rule with a class selector for the others). Page styles are disabled for it | Set `framework` explicitly, or [write a driver](../README.md#writing-your-own-driver) |
| BW6003 | info in the header, warning when logged | Rendered views have no analysis entry because they sit outside `paths`. The page links the full stylesheet and the message names the views | Add those views' directory to `paths`. With `pages.unanalysed` set to `html` the page is built from their rendered classes instead, and this is logged only when *no* view had an entry |
| BW6004 | warning | A page stylesheet could not be written because the store's atomic rename failed. Other write failures (a full disk, a directory that cannot be created) raise an `ErrorException` instead and surface as BW6001 | Make `public/bladewind` (or your `assets.path`) writable by the web server |
| BW6005 | info, logged once as a warning | A Tailwind 4 theme or properties layer has a shape this version does not take apart, so the root keeps it whole and every page pays for it. The message names the shape | Nothing required; pages are correct, the root is just larger. Please report the shape |
