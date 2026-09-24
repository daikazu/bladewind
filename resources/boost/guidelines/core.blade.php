@verbatim
## BladeWind

This application uses `daikazu/bladewind`, which serves each page only the CSS rules its Blade views can produce. It learns a page's classes by reading Blade source and rendered HTML, so a class it cannot see has no rule on that page, and that page alone renders unstyled.

- Write class names out in full in Blade. BladeWind cannot enumerate a class built at runtime (`"bg-{$color}-500"`). Pick between complete names instead, with `@class`, a `match`, or an array lookup.
- Classes that come from outside Blade (JavaScript, database content, a React or Vue island) go in `config/bladewind.php`: `safelist` for tokens every page needs, or `components.<view>.classes` for tokens one view adds at runtime.
- Keep the CSS entry out of `@vite`. The layout calls `@vite([...js entries])` followed by `@bladewindStyles`, which links the stylesheet itself.
- With `npm run dev` running, every page falls back to the full stylesheet. That is expected, not a bug to fix. Run `npm run build` to see per-page styles.
- When one page looks wrong, set `pages.enabled` to `false` and reload. If the page looks right with the full stylesheet, a class is missing from BladeWind's view of that page: find where it comes from and declare it.
- Diagnostics carry `BW` codes. `php artisan bladewind:inspect <view>` shows what one view produces.
@endverbatim
