<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * Every class token present in a rendered document.
 */
final class HtmlClassScanner
{
    private const STYLE_BLOCK = '~<style\b[^>]*>.*?</style\s*>~is';

    private const SCRIPT_BLOCK = '~<script\b([^>]*)>.*?</script\s*>~is';

    private const SCRIPT_TYPE = '~\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i';

    /**
     * Script types whose contents are code rather than markup. Any other type (`text/template`,
     * `text/x-template`, ...) is scanned: a template cloned into the page at runtime wears real
     * classes, and over-including a token costs one rule while missing one mis-styles.
     */
    private const JAVASCRIPT_TYPES = ['', 'module', 'importmap', 'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'speculationrules'];

    private const ATTRIBUTE = '~\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i';

    /**
     * @return list<string> sorted, unique
     */
    public static function tokens(string $html): array
    {
        if ($html === '' || stripos($html, 'class') === false) {
            return [];
        }

        $stripped = self::withoutCode($html);
        preg_match_all(self::ATTRIBUTE, $stripped, $matches);

        $tokens = [];
        $seen = [];

        foreach ([...$matches[1], ...$matches[2], ...$matches[3]] as $value) {
            if ($value === '') {
                continue;
            }

            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach (preg_split('~\s+~', trim($value)) ?: [] as $token) {
                // Tokens are collected as values, not keys: a numeric key such as '24' becomes
                // int(24), so array_keys() on the seen-set would not return the original string.
                if ($token === '' || isset($seen[$token])) {
                    continue;
                }

                $seen[$token] = true;
                $tokens[] = $token;
            }
        }

        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * $html with every `<style>` block and every JavaScript `<script>` block removed, so a `class`
     * mentioned in CSS or in a script's own strings is not read as one the page wears.
     */
    private static function withoutCode(string $html): string
    {
        $stripped = preg_replace(self::STYLE_BLOCK, '', $html) ?? $html;

        return preg_replace_callback(
            self::SCRIPT_BLOCK,
            static fn (array $match): string => self::isJavaScript((string) $match[1]) ? '' : (string) $match[0],
            $stripped,
        ) ?? $stripped;
    }

    /**
     * Whether a `<script>` tag's attributes say it holds JavaScript, which is the case when it
     * declares no type at all.
     */
    private static function isJavaScript(string $attributes): bool
    {
        if (preg_match(self::SCRIPT_TYPE, $attributes, $matches) !== 1) {
            return true;
        }

        // Only one of the three alternatives ever matches, and PCRE omits trailing groups entirely.
        $type = strtolower(trim($matches[1].($matches[2] ?? '').($matches[3] ?? '')));

        return in_array($type, self::JAVASCRIPT_TYPES, true);
    }
}
