<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\Http\InjectPageStyles;

/**
 * What {@see InjectPageStyles} puts in place of the `@bladewindStyles` placeholder:
 * the stylesheet link tags, a one-line description of how they were arrived at for the debug
 * `X-BladeWind-Styles` header, and whether they are the full-stylesheet fallback rather than this
 * page's own root and page stylesheets.
 */
final readonly class PageLinks
{
    /**
     * @param  array<string, mixed>  $metrics  what the debug panel shows: empty unless debug mode is on
     *                                         (the shape is {@see DebugPanel::render()}'s parameter)
     * @param  float  $debugMilliseconds  how long producing these links spent on work only debug mode
     *                                    does (the header's support count, the panel's sizes), so the
     *                                    panel can report the time a production request would have paid
     */
    public function __construct(
        public string $html,
        public string $header,
        public bool $fallback,
        public array $metrics = [],
        public float $debugMilliseconds = 0.0,
    ) {}
}
