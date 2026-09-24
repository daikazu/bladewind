<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Http\InjectPageStyles;

final class StylesDirective
{
    /**
     * The attribute `@bladewindStyles` puts on each `<link>` it emits, and the only thing
     * {@see InjectPageStyles} looks for when it swaps those links for this page's own. The
     * directive's output is already complete, so a response nothing replaces (a later minifier, a
     * route outside the global middleware, BladeWind disabled) still renders as `@vite` would.
     */
    public const MARKER = 'data-bladewind-styles';

    /**
     * What the directive leaves behind when nothing at all is configured (`bladewind.stylesheets`
     * is empty). The middleware replaces it like a marked run, so the page still gets whatever the
     * middleware can resolve, and BW6001 is logged when even that comes back empty. An entry that
     * *is* configured but cannot be resolved does not come here: the directive throws, as `@vite`
     * would ({@see PageStyles::markedLinks()}).
     */
    public const PLACEHOLDER = '<!--bladewind:styles-->';

    public function __construct(private PageStyles $pages) {}

    /**
     * The full stylesheet, linked and marked for replacement.
     */
    public function render(): string
    {
        $links = $this->pages->markedLinks();

        return $links === '' ? self::PLACEHOLDER : $links;
    }
}
