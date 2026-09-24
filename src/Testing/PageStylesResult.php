<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Testing;

/**
 * What one {@see AssertsPageStyles::assertPageStyles()} call found, for assertions the trait does
 * not make itself (a keyframe in the root, a variable in neither file).
 */
final readonly class PageStylesResult
{
    /**
     * @param  array<string, string>  $header  the `X-BladeWind-Styles` fields by name; a fallback carries only `fallback`
     * @param  string  $rootCss  empty on a fallback
     * @param  string  $pageCss  empty on a fallback; the inlined CSS under inline delivery
     * @param  string  $rootFile  file name under the store directory; empty on a fallback
     * @param  string  $pageFile  file name under the store directory; empty on a fallback
     * @param  string  $delivery  `link`, `inline` or `fallback`
     * @param  list<string>  $diagnostics  sorted unique BW codes found on the rendered views and in the log
     */
    public function __construct(
        public string $uri,
        public string $html,
        public array $header,
        public bool $fallback,
        public string $rootCss,
        public string $pageCss,
        public string $fullCss,
        public string $rootFile,
        public string $pageFile,
        public string $delivery,
        public array $diagnostics,
    ) {}
}
