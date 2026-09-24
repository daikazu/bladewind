<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

/**
 * Structural scanner for Alpine class bindings. Value positions are the top-level
 * expression, ternary branches, the right operand of &&, both operands of || and ??, array
 * elements, and object keys. Object values and the left operand of && are conditions.
 */
final class AlpineExpression
{
    private const LEXER = '/\G(?:(?<ws>\s+)|(?<comment>\/\/[^\n]*|\/\*.*?\*\/)|(?<string>\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")|(?<template>`(?:\\\\.|[^`\\\\])*`)|(?<number>\d+(?:\.\d+)?)|(?<ident>[A-Za-z_$][A-Za-z0-9_$]*)|(?<op>&&|\|\||\?\?|===|!==|==|!=|<=|>=|=>|[-+*\/%<>=!&|^~])|(?<punct>[{}\[\]().,:?;])|(?<other>.))/xs';

    private const OPENERS = ['(' => ')', '[' => ']', '{' => '}'];

    /**
     * @var list<array{type: string, text: string, pos: int}>
     */
    private array $tokens = [];

    /**
     * @var list<string>
     */
    private array $found = [];

    /**
     * @var list<ConditionalTokens>
     */
    private array $conditions = [];

    /**
     * @var list<string>
     */
    private array $unresolved = [];

    private bool $literalSeen = false;

    private function __construct(private string $source)
    {
        $this->tokens = $this->lex($source);
    }

    public static function scan(string $source): AlpineScan
    {
        $instance = new self($source);

        if ($instance->tokens !== []) {
            $instance->value(0, count($instance->tokens), null);
        }

        $classification = match (true) {
            $instance->literalSeen && $instance->unresolved === [] => RuntimeClassification::Enumerable,
            $instance->literalSeen => RuntimeClassification::Partial,
            default => RuntimeClassification::Unresolved,
        };

        return new AlpineScan($instance->found, $instance->conditions, $instance->unresolved, $classification);
    }

    /**
     * @return list<array{type: string, text: string, pos: int}>
     */
    private function lex(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (preg_match(self::LEXER, $source, $match, 0, $offset) !== 1) {
                break;
            }

            $text = $match[0];

            foreach (['string', 'template', 'number', 'ident', 'op', 'punct', 'other'] as $type) {
                if (($match[$type] ?? '') !== '') {
                    $tokens[] = ['type' => $type, 'text' => $text, 'pos' => $offset];

                    break;
                }
            }

            $offset += strlen($text);
        }

        return $tokens;
    }

    private function value(int $start, int $end, ?string $condition): void
    {
        if ($start >= $end) {
            return;
        }

        $question = $this->findTopLevel($start, $end, '?');

        if ($question !== null) {
            $colon = $this->matchingColon($question + 1, $end);

            if ($colon === null) {
                $this->unresolved[] = $this->render($start, $end);

                return;
            }

            $test = $this->render($start, $question);
            $this->value($question + 1, $colon, $test);
            $this->value($colon + 1, $end, $test);

            return;
        }

        $operands = $this->splitTopLevel($start, $end, ['||', '??']);

        if (count($operands) > 1) {
            foreach ($operands as [$from, $to]) {
                $this->value($from, $to, $condition);
            }

            return;
        }

        $operands = $this->splitTopLevel($start, $end, ['&&']);

        if (count($operands) > 1) {
            [$from, $to] = $operands[count($operands) - 1];
            $this->value($from, $to, $this->render($start, $operands[count($operands) - 2][1]));

            return;
        }

        $first = $this->tokens[$start];

        if ($end - $start === 1 && $first['type'] === 'string') {
            $this->literal(self::unquote($first['text']), $condition);

            return;
        }

        if ($end - $start === 1 && $first['type'] === 'template') {
            if (str_contains($first['text'], '${')) {
                $this->unresolved[] = $first['text'];
            } else {
                $this->literal(self::unquote($first['text']), $condition);
            }

            return;
        }

        if (isset(self::OPENERS[$first['text']]) && $this->matching($start, $end) === $end - 1) {
            if ($first['text'] === '(') {
                $this->value($start + 1, $end - 1, $condition);
            } elseif ($first['text'] === '{') {
                $this->object($start + 1, $end - 1, $condition);
            } else {
                $this->array($start + 1, $end - 1, $condition);
            }

            return;
        }

        $this->unresolved[] = $this->render($start, $end);
    }

    private function array(int $start, int $end, ?string $condition): void
    {
        foreach ($this->splitTopLevel($start, $end, [',']) as [$from, $to]) {
            $this->value($from, $to, $condition);
        }
    }

    private function object(int $start, int $end, ?string $condition): void
    {
        foreach ($this->splitTopLevel($start, $end, [',']) as [$from, $to]) {
            if ($from >= $to) {
                continue;
            }

            $colon = $this->findTopLevel($from, $to, ':');

            if ($colon === null) {
                $this->unresolved[] = $this->render($from, $to);

                continue;
            }

            $valueText = $this->render($colon + 1, $to);
            $this->key($from, $colon, $valueText !== '' ? $valueText : $condition, $this->render($from, $to));
        }
    }

    private function key(int $start, int $end, ?string $condition, string $pairSource): void
    {
        $count = $end - $start;
        $first = $this->tokens[$start];

        if ($count === 1 && $first['type'] === 'string') {
            $this->literal(self::unquote($first['text']), $condition);

            return;
        }

        if ($count === 1 && $first['type'] === 'ident') {
            $this->literal($first['text'], $condition);

            return;
        }

        if ($count === 3 && $first['text'] === '[' && $this->tokens[$start + 1]['type'] === 'string' && $this->tokens[$start + 2]['text'] === ']') {
            $this->literal(self::unquote($this->tokens[$start + 1]['text']), $condition);

            return;
        }

        $this->unresolved[] = $pairSource;
    }

    private function literal(string $classes, ?string $condition): void
    {
        $this->literalSeen = true;
        $tokens = Tokenizer::split($classes);

        if ($tokens === []) {
            return;
        }

        $this->found = [...$this->found, ...$tokens];

        if ($condition !== null) {
            $this->conditions[] = new ConditionalTokens($tokens, $condition);
        }
    }

    private function findTopLevel(int $start, int $end, string $text): ?int
    {
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if (isset(self::OPENERS[$token['text']]) && $token['type'] === 'punct') {
                $depth++;
            } elseif (in_array($token['text'], self::OPENERS, true) && $token['type'] === 'punct') {
                $depth--;
            } elseif ($depth === 0 && $token['text'] === $text) {
                return $i;
            }
        }

        return null;
    }

    private function matchingColon(int $from, int $end): ?int
    {
        $depth = 0;
        $nested = 0;

        for ($i = $from; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if (isset(self::OPENERS[$token['text']]) && $token['type'] === 'punct') {
                $depth++;
            } elseif (in_array($token['text'], self::OPENERS, true) && $token['type'] === 'punct') {
                $depth--;
            } elseif ($depth === 0 && $token['text'] === '?') {
                $nested++;
            } elseif ($depth === 0 && $token['text'] === ':') {
                if ($nested === 0) {
                    return $i;
                }

                $nested--;
            }
        }

        return null;
    }

    /**
     * Index of the bracket that closes the opener at $open, or null.
     */
    private function matching(int $open, int $end): ?int
    {
        $opener = $this->tokens[$open]['text'];
        $closer = self::OPENERS[$opener];
        $depth = 0;

        for ($i = $open; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if ($token['type'] !== 'punct') {
                continue;
            }

            if ($token['text'] === $opener) {
                $depth++;
            } elseif ($token['text'] === $closer) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Split [$start, $end) on any of $separators at depth zero. Returns [from, to) pairs.
     *
     * @param  list<string>  $separators
     * @return list<array{0: int, 1: int}>
     */
    private function splitTopLevel(int $start, int $end, array $separators): array
    {
        $parts = [];
        $depth = 0;
        $from = $start;

        for ($i = $start; $i < $end; $i++) {
            $token = $this->tokens[$i];

            if (isset(self::OPENERS[$token['text']]) && $token['type'] === 'punct') {
                $depth++;
            } elseif (in_array($token['text'], self::OPENERS, true) && $token['type'] === 'punct') {
                $depth--;
            } elseif ($depth === 0 && in_array($token['text'], $separators, true)) {
                $parts[] = [$from, $i];
                $from = $i + 1;
            }
        }

        $parts[] = [$from, $end];

        return $parts;
    }

    private function render(int $from, int $to): string
    {
        if ($from >= $to) {
            return '';
        }

        $start = $this->tokens[$from]['pos'];
        $last = $this->tokens[$to - 1];

        return trim(substr($this->source, $start, $last['pos'] + strlen($last['text']) - $start));
    }

    private static function unquote(string $literal): string
    {
        return Unescape::quotesAndBackslashes(substr($literal, 1, -1));
    }
}
