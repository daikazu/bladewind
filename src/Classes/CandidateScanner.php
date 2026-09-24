<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

use PhpToken;

/**
 * Tailwind-style candidate extraction: every whitespace-separated token inside a string
 * literal that could be a utility class. Candidates are possibilities, never proof.
 */
final class CandidateScanner
{
    public const JS = 'js';

    public const PHP = 'php';

    private const ALLOWED = '/^[A-Za-z0-9_\-:\/.\[\]()%#!@,\'*&+=<>~^\\\\]+$/';

    private const JS_STRINGS = '/"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|`((?:\\\\.|[^`\\\\])*)`/s';

    /**
     * @return list<string>
     */
    public static function scan(string $source, string $language): array
    {
        $strings = $language === self::PHP ? self::phpStrings($source) : self::jsStrings($source);

        $seen = [];
        $candidates = [];

        foreach ($strings as $string) {
            foreach (Tokenizer::split($string) as $token) {
                if (isset($seen[$token]) || ! self::looksLikeClass($token)) {
                    continue;
                }

                $seen[$token] = true;
                $candidates[] = $token;
            }
        }

        return $candidates;
    }

    private static function looksLikeClass(string $token): bool
    {
        return preg_match('/[A-Za-z]/', $token) === 1 && preg_match(self::ALLOWED, $token) === 1;
    }

    /**
     * @return list<string>
     */
    private static function jsStrings(string $source): array
    {
        preg_match_all(self::JS_STRINGS, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $strings = [];

        foreach ($matches as $match) {
            if (isset($match[3])) {
                // A template literal: its literal text is one string, and every `${...}` placeholder
                // is JavaScript with strings of its own (`${open ? 'a' : 'b'}`), read the same way.
                // Read as a single string, `'b'}` would fail the allow-list and `'a'` keep its quotes.
                $strings = [...$strings, ...self::templateStrings($match[3])];

                continue;
            }

            $content = $match[1] ?? $match[2] ?? '';
            $strings[] = Unescape::quotesAndBackslashes($content);
        }

        return $strings;
    }

    /**
     * The strings a template literal's body holds: its own text with every placeholder blanked,
     * then the strings of each placeholder's expression, placeholders nested in placeholders
     * included.
     *
     * @return list<string>
     */
    private static function templateStrings(string $body): array
    {
        $strings = [];
        $literal = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '\\') {
                $literal .= substr($body, $i, 2);
                $i++;

                continue;
            }

            if ($body[$i] === '$' && ($body[$i + 1] ?? '') === '{') {
                $depth = 0;

                for ($j = $i + 1; $j < $length; $j++) {
                    if ($body[$j] === '{') {
                        $depth++;
                    } elseif ($body[$j] === '}' && --$depth === 0) {
                        break;
                    }
                }

                $strings = [...$strings, ...self::jsStrings(substr($body, $i + 2, $j - $i - 2))];
                $literal .= ' ';
                $i = $j;

                continue;
            }

            $literal .= $body[$i];
        }

        return [Unescape::quotesAndBackslashes($literal), ...$strings];
    }

    /**
     * @return list<string>
     */
    private static function phpStrings(string $source): array
    {
        $strings = [];

        foreach (PhpToken::tokenize('<?php '.$source) as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $strings[] = Unescape::quotesAndBackslashes(substr($token->text, 1, -1));
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                $strings[] = Unescape::quotesAndBackslashes($token->text);
            }
        }

        return $strings;
    }
}
