<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

use Daikazu\BladeWind\Resolution\Argument;
use Daikazu\BladeWind\Resolution\ArgumentKind;
use Daikazu\BladeWind\Resolution\DirectiveArguments;
use PhpToken;

/**
 * Reads the array passed to @class([...]), $attributes->class([...]),
 * Arr::toCssClasses([...]), and the 'class' key of $attributes->merge([...]).
 */
final class ConditionalHelper
{
    private const NO_ARRAY_ARGUMENT = '(no array argument)';

    public static function parse(?Argument $array): ClassExpressionResult
    {
        if ($array === null) {
            return new ClassExpressionResult([], [], [self::NO_ARRAY_ARGUMENT]);
        }

        if ($array->kind !== ArgumentKind::ArrayLiteral) {
            return new ClassExpressionResult([], [], [$array->source]);
        }

        $static = [];
        $conditions = [];
        $unresolved = [];

        foreach ($array->elements as $element) {
            if ($element->isLiteral()) {
                $static = [...$static, ...Tokenizer::split((string) $element->value)];

                continue;
            }

            $pair = self::pair($element->source);

            if ($pair === null) {
                $unresolved[] = $element->source;

                continue;
            }

            $conditions[] = new ConditionalTokens(Tokenizer::split($pair['key']), $pair['value']);
        }

        return new ClassExpressionResult($static, $conditions, $unresolved);
    }

    public static function mergeClasses(?Argument $array): ClassExpressionResult
    {
        if ($array === null) {
            return new ClassExpressionResult([], [], [self::NO_ARRAY_ARGUMENT]);
        }

        if ($array->kind !== ArgumentKind::ArrayLiteral) {
            return new ClassExpressionResult([], [], [$array->source]);
        }

        foreach ($array->elements as $element) {
            $pair = self::pair($element->source);

            if ($pair === null || $pair['key'] !== 'class') {
                continue;
            }

            $value = DirectiveArguments::split('('.$pair['value'].')')[0] ?? null;

            if ($value === null) {
                return ClassExpressionResult::empty();
            }

            if ($value->isLiteral()) {
                return new ClassExpressionResult(Tokenizer::split((string) $value->value), [], []);
            }

            if ($value->kind === ArgumentKind::ArrayLiteral) {
                return self::parse($value);
            }

            return new ClassExpressionResult([], [], [$value->source]);
        }

        return ClassExpressionResult::empty();
    }

    /**
     * Split "'key' => value" into its unquoted key and the verbatim value source.
     *
     * @return array{key: string, value: string}|null
     */
    private static function pair(string $source): ?array
    {
        $padded = '<?php '.$source;
        $tokens = array_values(array_filter(
            PhpToken::tokenize($padded),
            static fn (PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        if (count($tokens) < 3 || ! $tokens[0]->is(T_CONSTANT_ENCAPSED_STRING) || ! $tokens[1]->is(T_DOUBLE_ARROW)) {
            return null;
        }

        $valueStart = $tokens[1]->pos + strlen($tokens[1]->text);
        $value = trim(substr($padded, $valueStart));

        // A second top-level "=>" means this is not a single pair.
        if (self::hasTopLevelArrow($tokens, 2)) {
            return null;
        }

        return ['key' => DirectiveArguments::unquote($tokens[0]->text), 'value' => $value];
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function hasTopLevelArrow(array $tokens, int $from): bool
    {
        $depth = 0;

        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            } elseif ($depth === 0 && $token->is(T_DOUBLE_ARROW)) {
                return true;
            }
        }

        return false;
    }
}
