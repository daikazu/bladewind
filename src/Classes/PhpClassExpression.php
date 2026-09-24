<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

use Daikazu\BladeWind\Resolution\DirectiveArguments;
use PhpToken;

/**
 * Enumerates the class strings a PHP echo expression can produce without evaluating it.
 * Grammar: expr := operand ( '?' expr ':' expr | '?:' expr | '??' expr )?
 *          operand := string literal | '(' expr ')' | other
 */
final class PhpClassExpression
{
    private const PREFIX = '<?php ';

    private string $padded;

    /**
     * @var list<PhpToken>
     */
    private array $tokens;

    /**
     * @var list<string>
     */
    private array $static = [];

    /**
     * @var list<ConditionalTokens>
     */
    private array $conditions = [];

    /**
     * @var list<string>
     */
    private array $unresolved = [];

    private function __construct(string $expression)
    {
        $this->padded = self::PREFIX.trim($expression);

        $tokens = PhpToken::tokenize($this->padded);
        array_shift($tokens);

        $this->tokens = array_values(array_filter(
            $tokens,
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    public static function enumerate(string $expression): ClassExpressionResult
    {
        $instance = new self($expression);
        $instance->walk(0, count($instance->tokens), null);

        return new ClassExpressionResult($instance->static, $instance->conditions, $instance->unresolved);
    }

    private function walk(int $start, int $end, ?string $condition): void
    {
        if ($start >= $end) {
            return;
        }

        $question = $this->findTopLevel($start, $end, '?');

        if ($question !== null) {
            $test = $this->render($start, $question);

            if ($question + 1 < $end && $this->tokens[$question + 1]->is(':')) {
                $this->valueOrUnresolved($start, $question, $condition);
                $this->walk($question + 2, $end, $test);

                return;
            }

            $colon = $this->matchingColon($question + 1, $end);

            if ($colon === null) {
                $this->unresolved[] = $this->render($start, $end);

                return;
            }

            $this->walk($question + 1, $colon, $test);
            $this->walk($colon + 1, $end, $test);

            return;
        }

        $coalesce = $this->findTopLevel($start, $end, T_COALESCE);

        if ($coalesce !== null) {
            $left = $this->render($start, $coalesce);
            $this->valueOrUnresolved($start, $coalesce, $condition);
            $this->walk($coalesce + 1, $end, $left);

            return;
        }

        if ($end - $start === 1 && $this->tokens[$start]->is(T_CONSTANT_ENCAPSED_STRING)) {
            $tokens = Tokenizer::split(DirectiveArguments::unquote($this->tokens[$start]->text));

            if ($tokens === []) {
                return;
            }

            if ($condition === null) {
                $this->static = [...$this->static, ...$tokens];
            } else {
                $this->conditions[] = new ConditionalTokens($tokens, $condition);
            }

            return;
        }

        if ($this->tokens[$start]->is('(') && $this->matchingParen($start, $end) === $end - 1) {
            $this->walk($start + 1, $end - 1, $condition);

            return;
        }

        $this->unresolved[] = $this->render($start, $end);
    }

    /**
     * The left side of `?:` and `??` is a value only when it is a literal or parenthesised.
     */
    private function valueOrUnresolved(int $start, int $end, ?string $condition): void
    {
        $single = $end - $start === 1 && $this->tokens[$start]->is(T_CONSTANT_ENCAPSED_STRING);
        $wrapped = $this->tokens[$start]->is('(') && $this->matchingParen($start, $end) === $end - 1;

        if ($single || $wrapped) {
            $this->walk($start, $end, $condition);

            return;
        }

        $this->unresolved[] = $this->render($start, $end);
    }

    private function findTopLevel(int $start, int $end, int|string $needle): ?int
    {
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            } elseif ($depth === 0 && $token->is($needle)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the ':' that closes the ternary whose '?' sits just before $from, skipping nested
     * ternaries (including short ones, whose '?' and ':' cancel out).
     */
    private function matchingColon(int $from, int $end): ?int
    {
        $depth = 0;
        $nested = 0;

        for ($i = $from; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            } elseif ($depth === 0 && $token->is('?')) {
                $nested++;
            } elseif ($depth === 0 && $token->is(':')) {
                if ($nested === 0) {
                    return $i;
                }

                $nested--;
            }
        }

        return null;
    }

    private function matchingParen(int $open, int $end): ?int
    {
        $depth = 0;

        for ($i = $open; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if ($token->is('(')) {
                $depth++;
            } elseif ($token->is(')')) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Original source text spanning the tokens [$from, $to), whitespace preserved.
     */
    private function render(int $from, int $to): string
    {
        if ($from >= $to) {
            return '';
        }

        $first = $this->tokens[$from];
        $last = $this->tokens[$to - 1];
        $startOffset = $first->pos;
        $endOffset = $last->pos + strlen($last->text);

        return trim(substr($this->padded, $startOffset, $endOffset - $startOffset));
    }
}
