<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes\Adapters;

use Daikazu\BladeWind\Classes\MixedValue;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\RuntimeClassification;
use Daikazu\BladeWind\Classes\Tokenizer;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Element;

/**
 * wire:* directives whose modifier list contains "class", and `wire:current`, whose value is a
 * class list Livewire's own JavaScript adds to a link whose `href` matches the current URL. Those
 * classes are never in the server-rendered HTML, so the inventory is the only way they reach the
 * page.
 */
final class LivewireAdapter implements RuntimeAdapter
{
    public function attributes(Element|ComponentTag $node): array
    {
        $runtime = [];

        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);

            if ($attribute->kind !== BindingKind::Static || ! str_starts_with($name, 'wire:')) {
                continue;
            }

            [$directive, $modifiers] = self::parts(substr($name, 5));

            if ($directive !== 'current' && ! in_array('class', $modifiers, true)) {
                continue;
            }

            $remove = in_array('remove', $modifiers, true);
            $value = (string) $attribute->value;

            if (! $attribute->containsEchoes) {
                $runtime[] = new RuntimeClasses(
                    RuntimeClasses::ADAPTER_LIVEWIRE, $attribute->rawName(), RuntimeClassification::Static, $remove,
                    Tokenizer::split($value), [], [], $node->position->startLine, $node->position->startColumn,
                );

                continue;
            }

            $result = MixedValue::enumerate($value);

            $runtime[] = new RuntimeClasses(
                RuntimeClasses::ADAPTER_LIVEWIRE, $attribute->rawName(), RuntimeClassification::fromResult($result), $remove,
                [...$result->staticTokens, ...$result->enumerableTokens()], $result->conditions, $result->unresolved,
                $node->position->startLine, $node->position->startColumn,
            );
        }

        return $runtime;
    }

    /**
     * `loading.delay.class` → `loading` and `[delay, class]`.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function parts(string $name): array
    {
        $segments = explode('.', $name);

        return [$segments[0], array_slice($segments, 1)];
    }
}
