<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Http;

use Daikazu\BladeWind\Pages\NavigateScript;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Daikazu\BladeWind\Stylesheets\StylesheetIndex;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The route behind `assets.path`, reached only for a generated file the web server did not find on
 * disk (`try_files` and `php artisan serve` both serve an existing file first).
 *
 * A missing page or root stylesheet was written by another server (a load-balanced deployment with
 * no shared `public/`), pruned after something linked it, or removed by a deploy. The full
 * stylesheet is always correct, so it is served instead, marked `no-store` so a transient miss is
 * never cached. A missing file costs extra bytes rather than an unstyled page.
 *
 * The navigate script ({@see NavigateScript}) is served here too, by a versioned name, so a
 * Content Security Policy that allows only `'self'` can run it.
 */
final class ServeAsset
{
    public const PATTERN = 'bw-root-[0-9a-f]{12}\.css|bw-page-[0-9a-f]{12}-[0-9a-f]{12}\.css|bw-navigate-[0-9a-f]{12}\.js';

    public function __construct(
        private PageStyleStore $store,
        private StylesheetIndex $stylesheets,
    ) {}

    public function __invoke(string $file): Response
    {
        if ($file === NavigateScript::file()) {
            return self::response(NavigateScript::SOURCE, 'text/javascript', 'public, max-age=31536000, immutable');
        }

        if (str_ends_with($file, '.js')) {
            throw new NotFoundHttpException;
        }

        if ($this->store->has($file)) {
            $css = @file_get_contents($this->store->directory().'/'.$file);

            if (is_string($css) && $css !== '') {
                // Content-addressed: the name changes whenever the content would.
                return self::response($css, 'text/css', 'public, max-age=31536000, immutable');
            }
        }

        foreach ($this->stylesheets->stylesheets() as $stylesheet) {
            if ($stylesheet->found) {
                $css = $this->stylesheets->contents()[$stylesheet->name] ?? null;

                if ($css !== null) {
                    return self::response($css, 'text/css', 'no-store');
                }
            }
        }

        throw new NotFoundHttpException;
    }

    private static function response(string $body, string $type, string $cacheControl): Response
    {
        return new Response($body, 200, [
            'Content-Type' => $type.'; charset=utf-8',
            'Cache-Control' => $cacheControl,
        ]);
    }
}
