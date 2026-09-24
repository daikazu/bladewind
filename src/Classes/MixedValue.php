<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

/**
 * A class attribute value that mixes literal text with Blade echoes.
 */
final class MixedValue
{
    private const ECHO = '/(\{\{.*?\}\}|\{!!.*?!!\})/s';

    public static function enumerate(string $value): ClassExpressionResult
    {
        $parts = preg_split(self::ECHO, $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return ClassExpressionResult::empty();
        }

        $result = ClassExpressionResult::empty();
        $count = count($parts);

        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i];

            if ($i % 2 === 0) {
                $result = $result->merge(new ClassExpressionResult(self::literalTokens($parts, $i), [], []));

                continue;
            }

            $previous = $parts[$i - 1];
            $next = $parts[$i + 1] ?? '';

            if (self::gluedToEnd($previous) || self::gluedToStart($next)) {
                $result = $result->merge(new ClassExpressionResult([], [], [self::fragment($previous, $part, $next)]));

                continue;
            }

            $result = $result->merge(PhpClassExpression::enumerate(self::inner($part)));
        }

        return $result;
    }

    /**
     * Tokens of a literal segment, minus fragments glued to a neighbouring echo.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function literalTokens(array $parts, int $index): array
    {
        $part = $parts[$index];
        $tokens = Tokenizer::split($part);

        if ($index > 0 && self::gluedToStart($part) && $tokens !== []) {
            array_shift($tokens);
        }

        if ($index < count($parts) - 1 && self::gluedToEnd($part) && $tokens !== []) {
            array_pop($tokens);
        }

        return $tokens;
    }

    private static function gluedToEnd(string $text): bool
    {
        return $text !== '' && ! ctype_space($text[strlen($text) - 1]);
    }

    private static function gluedToStart(string $text): bool
    {
        return $text !== '' && ! ctype_space($text[0]);
    }

    private static function fragment(string $previous, string $echo, string $next): string
    {
        preg_match('/\S*$/', $previous, $before);
        preg_match('/^\S*/', $next, $after);

        return ($before[0] ?? '').$echo.($after[0] ?? '');
    }

    private static function inner(string $echo): string
    {
        if (str_starts_with($echo, '{!!')) {
            return trim(substr($echo, 3, -3));
        }

        return trim(substr($echo, 2, -2));
    }
}
