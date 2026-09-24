<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Http;

use Closure;
use Daikazu\BladeWind\Pages\DebugPanel;
use Daikazu\BladeWind\Pages\PageLinks;
use Daikazu\BladeWind\Pages\PageStyles;
use Daikazu\BladeWind\Pages\RenderedViews;
use Daikazu\BladeWind\Pages\StylesDirective;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Global middleware that swaps the stylesheet links `@bladewindStyles` emitted for this page's own.
 * The service provider pushes it, so an application needs no configuration for it.
 *
 * A response that is not an {@see Response} with string content, or that carries neither
 * {@see StylesDirective::MARKER} nor the comment placeholder, is returned untouched after one
 * `instanceof` and one or two `strpos`. Streamed, binary, JSON (Livewire updates included) and
 * redirect responses never reach the page styles machinery.
 *
 * The directive emits the full stylesheet itself, so a response this middleware skips or cannot
 * improve is still a styled page.
 */
final class InjectPageStyles
{
    /**
     * One run of marked `<link>` tags, i.e. one `@bladewindStyles`. Every run is replaced, so a
     * layout with the directive twice gets this page's links twice, not one full stylesheet.
     */
    private const MARKED_LINKS = '~(?:<link\b[^>]*\b'.StylesDirective::MARKER.'\b[^>]*>)+~i';

    /**
     * @var Closure(): PageStyles
     */
    private Closure $styles;

    /**
     * @param  Closure(): PageStyles  $styles  resolved on first use, so a failure building it is caught by
     *                                         {@see self::links()} and the page keeps the directive's own links
     */
    public function __construct(
        private RenderedViews $rendered,
        Closure $styles,
        private Repository $config,
    ) {
        $this->styles = $styles;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // Reset first: under Octane the collector is a long-lived singleton, and a request must
        // never inherit the views another one rendered.
        $this->rendered->reset();

        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content)) {
            return $response;
        }

        $marked = str_contains($content, StylesDirective::MARKER);
        $placeholder = strpos($content, StylesDirective::PLACEHOLDER);

        if (! $marked && $placeholder === false) {
            return $response;
        }

        $started = hrtime(true);
        $links = $this->links($content);
        $milliseconds = (hrtime(true) - $started) / 1e6;

        if ($marked) {
            if ($links === null) {
                // The directive's own links are already a complete stylesheet.
                return $response;
            }

            $count = 0;
            $replaced = self::replaceMarked($content, $links->html, $count);

            if ($replaced === null) {
                // The regex engine gave up (backtrack or recursion limit); serve the directive's
                // own links, which are complete.
                return $response;
            }

            if ($count === 0 && $placeholder !== false) {
                // The marker string appears in the page body (a code sample) while the directive
                // itself fell back to the comment: replace the comment instead.
                $replaced = substr_replace($content, $links->html, $placeholder, strlen(StylesDirective::PLACEHOLDER));
            }

            $response->setContent($replaced);
        } else {
            // Only the first occurrence: a page whose body happens to contain the literal comment
            // must not end up with the links repeated.
            $response->setContent(substr_replace(
                $content,
                $links === null ? '' : $links->html,
                (int) $placeholder,
                strlen(StylesDirective::PLACEHOLDER),
            ));
        }

        if ($links !== null && $this->debug()) {
            $response->headers->set('X-BladeWind-Styles', $links->header);
            $this->injectPanel($response, $links, $milliseconds);
        }

        return $response;
    }

    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false) || (bool) $this->config->get('bladewind.debug', false);
    }

    /**
     * Adds the metrics panel before the closing body tag (or at the end) when `bladewind.debug` is
     * on. The class set was built before this, so the panel's markup never reaches it. The time
     * shown excludes debug-only work, i.e. what a production request would have paid.
     */
    private function injectPanel(Response $response, PageLinks $links, float $milliseconds): void
    {
        if ((bool) $this->config->get('bladewind.debug', false) !== true) {
            return;
        }

        $panel = DebugPanel::render($links->metrics, max(0.0, $milliseconds - $links->debugMilliseconds), ($this->styles)()->nonceAttribute());
        $content = $response->getContent();

        if ($panel === '' || ! is_string($content)) {
            return;
        }

        $position = strripos($content, '</body>');

        $response->setContent($position === false ? $content.$panel : substr_replace($content, $panel, $position, 0));
    }

    /**
     * Every run of marked links replaced by $html; null when the regex engine fails, so the caller
     * can keep the directive's own (complete) links rather than an empty body.
     */
    public static function replaceMarked(string $content, string $html, int &$count = 0): ?string
    {
        $count = 0;

        return preg_replace_callback(self::MARKED_LINKS, static fn (): string => $html, $content, -1, $count);
    }

    /**
     * The links for this response, or null when even the fallback could not be produced. A page
     * carrying the comment placeholder then loses it; the failure has already been logged.
     */
    private function links(string $html): ?PageLinks
    {
        try {
            $styles = ($this->styles)();
        } catch (Throwable) {
            // Nothing can be built at all: the directive's links stand, and they are complete.
            return null;
        }

        try {
            return $styles->forResponse($html, $this->rendered->paths());
        } catch (Throwable $exception) {
            try {
                // Logs BW6001 once per process for this reason, then links the full stylesheet.
                return $styles->fallbackLinks($exception->getMessage());
            } catch (Throwable) {
                return null;
            }
        }
    }
}
