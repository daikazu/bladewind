<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final class Tokenizer
{
    /**
     * Split a class list on whitespace. Tokens are verbatim, ordered, and duplicates are kept.
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value));

        if ($parts === false) {
            return [];
        }

        return array_values(array_filter($parts, static fn (string $token): bool => $token !== ''));
    }
}
