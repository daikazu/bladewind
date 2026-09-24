<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Stylesheets;

/**
 * String- and comment-skipping primitives shared by the CSS walkers: a quoted string or a
 * `/* ... *\/` comment is skipped whole so its contents never affect brace or depth tracking.
 */
final class CssScanner
{
    /**
     * Index of the first byte after the closing quote matching the one at $start (backslashes
     * inside the string escape the following byte); the string's length when unterminated.
     */
    public static function skipString(string $css, int $start, int $length): int
    {
        $quote = $css[$start];
        $i = $start + 1;

        while ($i < $length) {
            if ($css[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($css[$i] === $quote) {
                return $i + 1;
            }

            $i++;
        }

        return $length;
    }

    /**
     * Index of the first byte after the closing `*` `/` pair for the comment opened at $start;
     * the string's length when unterminated.
     */
    public static function skipComment(string $css, int $start, int $length): int
    {
        $end = strpos($css, '*/', $start + 2);

        return $end === false ? $length : $end + 2;
    }
}
