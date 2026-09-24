<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use WeakMap;

/**
 * The support declarations each stylesheet a page loads has to carry: the theme variables, the
 * `@layer properties` defaults, the `@property` registrations and the `@keyframes` rules, subsetted
 * to what that file's own CSS reaches.
 *
 * {@see self::root()} carries what the root's remainder (its base and components layers, and
 * anything the splitter did not take apart) references. The page file carries the rest of what its
 * utilities and HTML reach. Together the two define every variable either of them reads.
 *
 * Keyframes are the exception: the root carries every `@keyframes` rule and a page file carries
 * none (see {@see self::rootKeyframes()}).
 *
 * The cascade survives the split because a layer's position is fixed by the first statement naming
 * it: the root keeps `@layer properties;` and `@layer theme;` even when it carries nothing from a
 * layer, and a page's later `@layer theme{...}` appends to the layer the root opened. `@property` is
 * position-independent, so both files put their registrations at the end.
 *
 * When the support layers could not be taken apart ({@see SplitStylesheet::$supportShaken} is
 * false), the root keeps every support declaration and the page carries only its utilities.
 */
final class SupportCssBuilder
{
    /**
     * The root file's variable names, memoised per split stylesheet since every page subtracts them.
     * A WeakMap so a split that goes out of scope takes its entries with it instead of growing this
     * builder for the life of a worker. The cache assumes one graph per split.
     *
     * The inner key is a hash of the companion CSS the names were closed over ({@see
     * self::rootNames()}).
     *
     * @var WeakMap<SplitStylesheet, array<string, list<string>>>
     */
    private WeakMap $rootNames;

    /**
     * The root file's `@keyframes` names, memoised per split stylesheet.
     *
     * @var WeakMap<SplitStylesheet, list<string>>
     */
    private WeakMap $rootKeyframes;

    public function __construct()
    {
        $this->rootNames = new WeakMap;
        $this->rootKeyframes = new WeakMap;
    }

    /**
     * The root file: the split's remainder with each support layer's statement expanded to the subset
     * that remainder references, the `@property` registrations it needs appended, and every
     * `@keyframes` rule of the stylesheet after them.
     *
     * A layer the remainder needs nothing from keeps its bare statement, which holds the layer's
     * place for every page that appends to it.
     *
     * @param  string  $companionCss  the other configured stylesheets, linked whole beside the root. The root
     *                                defines what they read, since no page file is built for them.
     */
    public function root(SplitStylesheet $split, VariableGraph $graph, string $companionCss = ''): string
    {
        if (! $split->supportShaken) {
            return $split->root;
        }

        $names = $this->rootNames($split, $graph, $companionCss);
        $keyed = self::keyed($names);
        $properties = self::propertiesCss($split, $keyed);
        $theme = self::themeCss($split, $keyed);

        /** @var array<string, string> $replacements */
        $replacements = [];

        if ($properties !== '') {
            $replacements['properties'] = $properties;
        }

        if ($theme !== '') {
            $replacements['theme'] = $theme;
        }

        return $split->withStatements($replacements)
            .self::propertyRules($split, $keyed)
            .self::keyframeRules($split, $this->rootKeyframes($split));
    }

    /**
     * The declared variables the root file carries, sorted: the transitive closure of what its
     * remainder, its `@keyframes` rules and $companionCss read. Keyframes count because Tailwind 4
     * animations can read theme variables (`@theme { @keyframes glow { ... var(--color-brand) } }`).
     *
     * A page subtracts this set from its own, since the root loads beside every page file.
     *
     * @param  string  $companionCss  see {@see self::root()}
     * @return list<string>
     */
    public function rootNames(SplitStylesheet $split, VariableGraph $graph, string $companionCss = ''): array
    {
        if (! $split->supportShaken) {
            return [];
        }

        $key = $companionCss === '' ? '' : hash('xxh128', $companionCss);
        $names = $this->rootNames[$split] ?? [];

        if (! isset($names[$key])) {
            $names[$key] = $graph->closure(VariableGraph::refs($split->root.implode('', $split->keyframes).$companionCss));
            $this->rootNames[$split] = $names;
        }

        return $names[$key];
    }

    /**
     * The keyframes the root file carries: every `@keyframes` rule the stylesheet defines, in
     * stylesheet order.
     *
     * Deliberately not a subset. Only CSS this package emitted reads a variable, but an animation can
     * be named by an inline style, a `<style>` block, `el.animate()` or another stylesheet, none of
     * which the server sees. Keyframes are position-independent and small (Tailwind's four are about
     * 300 bytes), so the root carries them all.
     *
     * @return list<string>
     */
    public function rootKeyframes(SplitStylesheet $split): array
    {
        return $this->rootKeyframes[$split] ??= array_keys($split->keyframes);
    }

    /**
     * One page's file: the support declarations it needs, then its own utilities layer.
     *
     * Order: the `--tw-*` defaults (so the properties layer opens before the theme layer, as in the
     * source stylesheet), the theme blocks in source order, the utilities, then the `@property`
     * registrations. An empty subset emits nothing. No `@keyframes` are emitted here.
     *
     * @param  list<string>  $names  the declared variables this page carries, less {@see self::rootNames()}. Closed again here so an unclosed list cannot emit a `var()` nothing defines; this may repeat a root declaration, which never changes what the cascade resolves.
     * @param  string  $utilitiesCss  the page's `@layer utilities{...}`, or `''` when it needs none
     */
    public function page(SplitStylesheet $split, VariableGraph $graph, array $names, string $utilitiesCss): string
    {
        if (! $split->supportShaken) {
            return $utilitiesCss;
        }

        $keyed = self::keyed($graph->closure($names));

        return self::propertiesCss($split, $keyed)
            .self::themeCss($split, $keyed)
            .$utilitiesCss
            .self::propertyRules($split, $keyed);
    }

    /**
     * `@layer properties{...}` holding the `--tw-*` defaults among $names, or `''` when the layer
     * declares none of them, so no empty `@supports` wrapper is left behind.
     *
     * @param  array<string, true>  $names
     */
    private static function propertiesCss(SplitStylesheet $split, array $names): string
    {
        if ($split->properties === null) {
            return '';
        }

        $css = $split->properties->css(array_intersect_key($split->properties->declarations, $names));

        return $css === '' ? '' : SplitStylesheet::PROPERTIES_PRELUDE.'{'.$css.'}';
    }

    /**
     * `@layer theme{...}` holding each block's share of $names, in block order and in each block's own
     * declaration order. A block none of the names belongs to is left out, and a layer with nothing
     * left in it emits nothing.
     *
     * @param  array<string, true>  $names
     */
    private static function themeCss(SplitStylesheet $split, array $names): string
    {
        $blocks = '';

        foreach ($split->theme as $block) {
            $blocks .= $block->css(array_intersect_key($block->declarations, $names));
        }

        return $blocks === '' ? '' : SplitStylesheet::THEME_PRELUDE.'{'.$blocks.'}';
    }

    /**
     * The `@property` registrations for the names among $names that have one, in stylesheet order. A
     * `--tw-*` is usually declared twice, once as a properties-layer default and once as this
     * registration's `initial-value`, and a file that carries the name carries both.
     *
     * @param  array<string, true>  $names
     */
    private static function propertyRules(SplitStylesheet $split, array $names): string
    {
        return implode('', array_intersect_key($split->propertyRules, $names));
    }

    /**
     * The whole `@keyframes` rules for $keyframes, in stylesheet order. A name the stylesheet does
     * not define contributes nothing.
     *
     * @param  list<string>  $keyframes  {@see self::rootKeyframes()}
     */
    private static function keyframeRules(SplitStylesheet $split, array $keyframes): string
    {
        return implode('', array_intersect_key($split->keyframes, array_flip($keyframes)));
    }

    /**
     * $names as a set, so `array_intersect_key()` subsets keep the declaration order of the block
     * rather than the order the names arrived in.
     *
     * @param  list<string>  $names
     * @return array<string, true>
     */
    private static function keyed(array $names): array
    {
        return array_fill_keys($names, true);
    }
}
