<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

/**
 * Unescapes only quotes and backslashes, leaving other escape sequences untouched. This
 * preserves Tailwind arbitrary values like content-['\2014'] intact, unlike stripcslashes()
 * or a full C-style unescaper, which would mangle the \2014 as an octal escape.
 */
final class Unescape
{
    public static function quotesAndBackslashes(string $content): string
    {
        return (string) preg_replace('/\\\\([\\\\\'"`])/', '$1', $content);
    }
}
