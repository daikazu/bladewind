<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes\Adapters;

use Daikazu\BladeWind\Classes\AlpineExpression;
use Daikazu\BladeWind\Classes\ConditionalTokens;
use Daikazu\BladeWind\Classes\MixedValue;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\RuntimeClassification;
use Daikazu\BladeWind\Parsing\Nodes\Attribute;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Element;

/**
 * Alpine class bindings and transition class lists.
 *
 * `:class` on an HTML element is Alpine; on a component tag it is a Blade bound attribute
 * holding PHP and is left to the class-group analysis. `::class` and `x-bind:class` are
 * Alpine on both.
 */
final class AlpineAdapter implements RuntimeAdapter
{
    private const TRANSITIONS = [
        'x-transition:enter', 'x-transition:enter-start', 'x-transition:enter-end',
        'x-transition:leave', 'x-transition:leave-start', 'x-transition:leave-end',
    ];

    private const ECHO = '/\{\{.*?\}\}|\{!!.*?!!\}/s';

    private const PLACEHOLDER = '__bladeEcho__';

    public function attributes(Element|ComponentTag $node): array
    {
        $runtime = [];

        foreach ($node->attributes as $attribute) {
            if ($this->isTransition($attribute)) {
                $runtime[] = $this->transition($attribute, $node);
            } elseif ($this->isBinding($attribute, $node)) {
                $runtime[] = $this->binding($attribute, $node);
            }
        }

        return $runtime;
    }

    private function isTransition(Attribute $attribute): bool
    {
        return $attribute->kind === BindingKind::Static && in_array($attribute->name, self::TRANSITIONS, true);
    }

    private function isBinding(Attribute $attribute, Element|ComponentTag $node): bool
    {
        $name = strtolower($attribute->name);

        if ($attribute->kind === BindingKind::Static && $name === 'x-bind:class') {
            return true;
        }

        if ($name !== 'class') {
            return false;
        }

        if ($attribute->kind === BindingKind::Escaped) {
            return $node instanceof ComponentTag;
        }

        return $attribute->kind === BindingKind::Bound && $node instanceof Element;
    }

    private function transition(Attribute $attribute, Element|ComponentTag $node): RuntimeClasses
    {
        $result = MixedValue::enumerate((string) $attribute->value);

        return new RuntimeClasses(
            RuntimeClasses::ADAPTER_ALPINE, $attribute->rawName(), RuntimeClassification::fromResult($result), false,
            $result->staticTokens, $result->conditions, $result->unresolved,
            $node->position->startLine, $node->position->startColumn,
        );
    }

    /**
     * Each Blade echo is swapped for its own placeholder identifier before the value is scanned
     * as JS, and recorded as unresolved. Distinct placeholders let a condition that captured one
     * (`{ 'hidden': {{ $open }} }`) get the original echo text back. An Alpine binding is never
     * static: it is enumerable, partial or unresolved.
     */
    private function binding(Attribute $attribute, Element|ComponentTag $node): RuntimeClasses
    {
        $value = (string) $attribute->value;
        $echoes = [];
        $placeholders = [];

        if (preg_match_all(self::ECHO, $value, $matches) > 0) {
            $echoes = $matches[0];
            $index = 0;
            $value = (string) preg_replace_callback(self::ECHO, function () use ($echoes, &$index, &$placeholders): string {
                $placeholder = self::PLACEHOLDER.$index;
                $placeholders[$placeholder] = $echoes[$index];
                $index++;

                return $placeholder;
            }, $value);
        }

        $scan = AlpineExpression::scan($value);
        $tokens = $this->withoutPlaceholder($scan->tokens);
        $conditions = $this->conditionsWithoutPlaceholder($scan->conditions, $placeholders);
        $unresolved = [...$scan->unresolved, ...$echoes];

        $classification = match (true) {
            $tokens !== [] && $unresolved === [] => RuntimeClassification::Enumerable,
            $tokens !== [] => RuntimeClassification::Partial,
            default => RuntimeClassification::Unresolved,
        };

        return new RuntimeClasses(
            RuntimeClasses::ADAPTER_ALPINE, $attribute->rawName(), $classification, false,
            $tokens, $conditions, $unresolved,
            $node->position->startLine, $node->position->startColumn,
        );
    }

    /**
     * Drops any token that still carries the placeholder substring, not just an exact match:
     * an echo glued to literal text (e.g. `bg-{{ $color }}-500`) survives substitution as one
     * token (`bg-__bladeEcho__-500`), and that whole token must never reach the caller.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function withoutPlaceholder(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! str_contains($token, self::PLACEHOLDER),
        ));
    }

    /**
     * @param  list<ConditionalTokens>  $conditions
     * @param  array<string, string>  $placeholders  Placeholder identifier => original echo text.
     * @return list<ConditionalTokens>
     */
    private function conditionsWithoutPlaceholder(array $conditions, array $placeholders): array
    {
        $filtered = [];

        foreach ($conditions as $condition) {
            $tokens = $this->withoutPlaceholder($condition->tokens);

            if ($tokens !== []) {
                $filtered[] = new ConditionalTokens($tokens, strtr($condition->condition, $placeholders));
            }
        }

        return $filtered;
    }
}
