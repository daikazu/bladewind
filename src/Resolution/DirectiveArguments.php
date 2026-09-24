<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

use PhpToken;

final class DirectiveArguments
{
    /**
     * Split a raw directive argument string such as "('a', $b)" into top-level arguments.
     *
     * Nothing is evaluated; the string is only tokenized.
     *
     * @return list<Argument>
     */
    public static function split(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $inner = trim($raw);

        if (str_starts_with($inner, '(') && str_ends_with($inner, ')')) {
            $inner = substr($inner, 1, -1);
        }

        if (trim($inner) === '') {
            return [];
        }

        $tokens = PhpToken::tokenize('<?php '.$inner.';');

        // Drop the open tag and the trailing semicolon we added.
        array_shift($tokens);
        array_pop($tokens);

        return self::splitTokens(array_values($tokens));
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<Argument>
     */
    private static function splitTokens(array $tokens): array
    {
        $groups = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($depth === 0 && $token->is(',')) {
                $groups[] = $current;
                $current = [];

                continue;
            }

            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            }

            $current[] = $token;
        }

        $groups[] = $current;

        $arguments = [];

        foreach ($groups as $group) {
            $argument = self::argumentFromTokens($group);

            if ($argument !== null) {
                $arguments[] = $argument;
            }
        }

        return $arguments;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function argumentFromTokens(array $tokens): ?Argument
    {
        $meaningful = array_values(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        if ($meaningful === []) {
            return null;
        }

        $source = trim(implode('', array_map(static fn (PhpToken $token): string => $token->text, $tokens)));

        if (count($meaningful) === 1 && $meaningful[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return new Argument(ArgumentKind::Literal, self::unquote($meaningful[0]->text), [], $source);
        }

        $first = $meaningful[0];
        $last = $meaningful[count($meaningful) - 1];

        if ($first->is('[') && $last->is(']') && self::bracketsEncloseWhole($meaningful)) {
            return new Argument(ArgumentKind::ArrayLiteral, null, self::splitTokens(self::innerTokens($tokens)), $source);
        }

        return new Argument(ArgumentKind::Expression, null, [], $source);
    }

    /**
     * The original tokens strictly between the first and last meaningful tokens (the enclosing brackets),
     * with whitespace preserved so element sources keep their spacing.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private static function innerTokens(array $tokens): array
    {
        $isMeaningful = static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]);
        $first = null;
        $last = null;

        foreach ($tokens as $index => $token) {
            if ($isMeaningful($token)) {
                $first ??= $index;
                $last = $index;
            }
        }

        if ($first === null || $last === null || $last - $first < 2) {
            return [];
        }

        return array_slice($tokens, $first + 1, $last - $first - 1);
    }

    /**
     * True when the opening "[" at index 0 is closed by the "]" at the last index,
     * rather than by an earlier bracket (as in "[1] + [2]").
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function bracketsEncloseWhole(array $tokens): bool
    {
        $depth = 0;
        $lastIndex = count($tokens) - 1;

        foreach ($tokens as $index => $token) {
            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;

                if ($depth === 0 && $index !== $lastIndex) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function unquote(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }
}
