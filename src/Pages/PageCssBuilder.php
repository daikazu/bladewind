<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

/**
 * The utility rules a page's class set needs, deduplicated by stylesheet position and re-emitted in
 * that order, plus the index's unconditional rules. The output is bare: any cascade-layer wrapper
 * is added by {@see PageStyles::utilitiesCss()}.
 *
 * Consecutive rules share one wrapper block only when their wrapper chains are equal and they sat
 * back-to-back in the original stylesheet. Equal wrapper text alone is not enough: the same
 * `@media` can appear twice, and an unselected rule between them hides whether they were one block.
 */
final class PageCssBuilder
{
    /**
     * @param  list<string>  $tokens  every class token the page uses
     */
    public function build(array $tokens, UtilityRuleIndex $index): string
    {
        /** @var array<int, IndexedRule> $byPosition keyed by stylesheet position, deduplicating a rule named by several tokens */
        $byPosition = [];

        foreach ($index->unconditional() as $rule) {
            $byPosition[$rule->position] = $rule;
        }

        foreach ($tokens as $token) {
            foreach ($index->rulesFor($token) as $rule) {
                $byPosition[$rule->position] = $rule;
            }
        }

        if ($byPosition === []) {
            return '';
        }

        ksort($byPosition);

        $css = '';
        /** @var list<string> $openWrappers */
        $openWrappers = [];
        $expectedNext = null;

        foreach ($byPosition as $rule) {
            $continuesOpenChain = $rule->wrappers === $openWrappers && $rule->position === $expectedNext;

            if (! $continuesOpenChain) {
                $css .= str_repeat('}', count($openWrappers));
                $css .= $rule->wrappers === [] ? '' : implode('{', $rule->wrappers).'{';
                $openWrappers = $rule->wrappers;
            }

            $text = $rule->css();
            $css .= $text;
            $expectedNext = $rule->position + strlen($text);
        }

        $css .= str_repeat('}', count($openWrappers));

        return $css;
    }
}
