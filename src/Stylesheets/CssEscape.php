<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Stylesheets;

/**
 * The CSS.escape algorithm (CSSOM), byte-wise: multibyte UTF-8 passes through untouched.
 */
final class CssEscape
{
    public static function escape(string $token): string
    {
        $escaped = '';
        $length = strlen($token);

        for ($i = 0; $i < $length; $i++) {
            $char = $token[$i];
            $code = ord($char);

            if ($code === 0) {
                $escaped .= "\u{FFFD}";

                continue;
            }

            if ($code >= 0x80) {
                $escaped .= $char;

                continue;
            }

            if ($code <= 0x1F || $code === 0x7F) {
                $escaped .= '\\'.dechex($code).' ';

                continue;
            }

            $digit = $code >= 0x30 && $code <= 0x39;

            if ($digit && ($i === 0 || ($i === 1 && $token[0] === '-'))) {
                $escaped .= '\\'.dechex($code).' ';

                continue;
            }

            if ($i === 0 && $length === 1 && $char === '-') {
                $escaped .= '\\-';

                continue;
            }

            if ($digit || $char === '-' || $char === '_' || ctype_alpha($char)) {
                $escaped .= $char;

                continue;
            }

            $escaped .= '\\'.$char;
        }

        return $escaped;
    }

    /**
     * The identifier with its CSS escapes resolved: the inverse of `escape()`, and also correct for
     * hand-written escapes. A backslash followed by hex digits is a hex escape (up to six digits,
     * plus one optional terminating space); otherwise it yields the byte after it.
     */
    public static function unescape(string $identifier): string
    {
        $unescaped = '';
        $length = strlen($identifier);

        for ($i = 0; $i < $length; $i++) {
            if ($identifier[$i] !== '\\') {
                $unescaped .= $identifier[$i];

                continue;
            }

            $i++;
            $hex = '';

            while ($i < $length && strlen($hex) < 6 && ctype_xdigit($identifier[$i])) {
                $hex .= $identifier[$i];
                $i++;
            }

            if ($hex === '') {
                // A trailing backslash escapes nothing; `escape()` never produces one.
                if ($i < $length) {
                    $unescaped .= $identifier[$i];
                }

                continue;
            }

            if (($identifier[$i] ?? '') !== ' ') {
                $i--;
            }

            $unescaped .= self::fromCodePoint((int) hexdec($hex));
        }

        return $unescaped;
    }

    /**
     * The UTF-8 bytes for a code point. `escape()` only hex-escapes ASCII, so the non-ASCII branch
     * serves stylesheets that escaped characters by hand.
     */
    private static function fromCodePoint(int $code): string
    {
        return $code >= 0 && $code <= 0x7F ? chr($code) : html_entity_decode('&#'.$code.';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
