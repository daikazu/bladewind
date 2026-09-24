<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Classes\Adapters\RuntimeAdapter;
use Daikazu\BladeWind\Classes\CandidateScanner;
use Daikazu\BladeWind\Classes\CandidateSet;
use Daikazu\BladeWind\Classes\ClassExpressionResult;
use Daikazu\BladeWind\Classes\ClassGroup;
use Daikazu\BladeWind\Classes\ConditionalHelper;
use Daikazu\BladeWind\Classes\GroupClassification;
use Daikazu\BladeWind\Classes\MixedValue;
use Daikazu\BladeWind\Classes\PhpClassExpression;
use Daikazu\BladeWind\Classes\RuntimeClasses;
use Daikazu\BladeWind\Classes\Tokenizer;
use Daikazu\BladeWind\Classes\UnresolvedConstruct;
use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Parsing\Nodes\Attribute;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Directive;
use Daikazu\BladeWind\Parsing\Nodes\EchoStatement;
use Daikazu\BladeWind\Parsing\Nodes\Element;
use Daikazu\BladeWind\Parsing\Nodes\Node;
use Daikazu\BladeWind\Parsing\Nodes\PhpBlock;
use Daikazu\BladeWind\Parsing\Nodes\Position;
use Daikazu\BladeWind\Parsing\Nodes\Template;
use Daikazu\BladeWind\Resolution\Argument;
use Daikazu\BladeWind\Resolution\DirectiveArguments;
use PhpToken;

/**
 * Walks a template once and records every class-bearing construct.
 */
final class ClassAnalyzer
{
    private const ECHO_SPLIT = '/(\{\{.*?\}\}|\{!!.*?!!\})/s';

    /**
     * @var list<ClassGroup>
     */
    private array $groups = [];

    /**
     * @var list<RuntimeClasses>
     */
    private array $runtime = [];

    /**
     * @var list<CandidateSet>
     */
    private array $candidates = [];

    /**
     * @var list<UnresolvedConstruct>
     */
    private array $unresolved = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * @param  iterable<RuntimeAdapter>  $adapters
     */
    public function __construct(private iterable $adapters) {}

    public function analyze(Template $template): ClassAnalysis
    {
        $this->groups = [];
        $this->runtime = [];
        $this->candidates = [];
        $this->unresolved = [];
        $this->diagnostics = [];

        foreach ($template->children as $child) {
            $this->visit($child, '');
        }

        return new ClassAnalysis(
            self::sorted($this->groups),
            self::sorted($this->runtime),
            self::sorted($this->candidates),
            self::sorted($this->unresolved),
            $this->diagnostics,
        );
    }

    private function visit(Node $node, string $parentTag): void
    {
        if ($node instanceof Element || $node instanceof ComponentTag) {
            $tag = $node instanceof ComponentTag ? $node->prefix.$node->name : $node->tagName;
            $on = $node instanceof ComponentTag ? ClassGroup::ON_COMPONENT : ClassGroup::ON_ELEMENT;

            $this->attributes($node, $tag, $on);
            $this->adapters($node, $tag);

            foreach ($node->children() as $child) {
                $this->visit($child, $tag);
            }

            return;
        }

        if ($node instanceof Directive && $node->name === 'class') {
            $this->classDirective($node->arguments, $parentTag, ClassGroup::ON_ELEMENT, $node->position);
        } elseif ($node instanceof EchoStatement) {
            $this->echo($node->content, $parentTag, ClassGroup::ON_ELEMENT, $node->position);
        } elseif ($node instanceof PhpBlock) {
            $this->phpBlock($node);
        }

        foreach ($node->children() as $child) {
            $this->visit($child, $parentTag);
        }
    }

    private function attributes(Element|ComponentTag $node, string $tag, string $on): void
    {
        foreach ($node->attributes as $attribute) {
            if ($attribute->kind === BindingKind::BladeConstruct) {
                if ($attribute->constructName === 'class') {
                    $this->classDirective($attribute->constructArguments, $tag, $on, $node->position);
                } elseif ($attribute->constructName === Attribute::CONSTRUCT_ECHO) {
                    $this->echo((string) $attribute->constructArguments, $tag, $on, $node->position);
                }

                continue;
            }

            if ($attribute->name !== 'class') {
                continue;
            }

            if ($attribute->kind === BindingKind::Static) {
                $this->classAttribute($attribute, $tag, $on, $node->position);
            } elseif ($attribute->kind === BindingKind::Bound && $node instanceof ComponentTag) {
                $result = PhpClassExpression::enumerate((string) $attribute->value);
                $this->group(ClassGroup::SOURCE_ATTRIBUTE, ClassGroup::ON_COMPONENT, $tag, $result, false, $node->position, UnresolvedConstruct::KIND_PHP_BOUND_CLASS);
            }
        }
    }

    private function classAttribute(Attribute $attribute, string $tag, string $on, Position $position): void
    {
        $value = (string) $attribute->value;

        if (! $attribute->containsEchoes) {
            $this->group(ClassGroup::SOURCE_ATTRIBUTE, $on, $tag, new ClassExpressionResult(Tokenizer::split($value), [], []), false, $position, UnresolvedConstruct::KIND_CLASS_ATTRIBUTE);

            return;
        }

        // A class attribute holding exactly one helper echo, e.g. class="{{ Arr::toCssClasses([...]) }}".
        $singleEcho = self::singleEchoValue($value);

        if ($singleEcho !== null) {
            $helper = $this->helper(self::inner($singleEcho));

            if ($helper !== null) {
                $this->helperGroup($helper, $tag, $on, $position);

                return;
            }
        }

        $this->group(ClassGroup::SOURCE_ATTRIBUTE, $on, $tag, MixedValue::enumerate($value), false, $position, UnresolvedConstruct::KIND_CLASS_ATTRIBUTE);
    }

    /**
     * The lone echo in $value, when every literal part around it is blank; null when the value
     * holds anything else (more than one echo, or non-blank literal text alongside one echo),
     * so the caller falls through to the mixed-value path instead of dropping the rest.
     */
    private static function singleEchoValue(string $value): ?string
    {
        $parts = preg_split(self::ECHO_SPLIT, $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return null;
        }

        $echo = null;

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                if ($echo !== null) {
                    return null;
                }

                $echo = $part;

                continue;
            }

            if (trim($part) !== '') {
                return null;
            }
        }

        return $echo;
    }

    private function classDirective(?string $arguments, string $tag, string $on, Position $position): void
    {
        $result = ConditionalHelper::parse(DirectiveArguments::split($arguments)[0] ?? null);

        $this->helperGroup(['source' => ClassGroup::SOURCE_CLASS_DIRECTIVE, 'label' => '@class', 'result' => $result, 'bag' => false], $tag, $on, $position);
    }

    private function echo(string $content, string $tag, string $on, Position $position): void
    {
        $helper = $this->helper(self::inner(trim($content)));

        if ($helper !== null) {
            $this->helperGroup($helper, $tag, $on, $position);
        }
    }

    /**
     * Recognise $attributes, $attributes->class([...]), $attributes->merge([...]) and
     * Arr::toCssClasses([...]) in an echo expression.
     *
     * Any other `$attributes` expression (an unknown method such as `->twMerge(...)`, or a chain
     * past a known call such as `->class([...])->merge([...])`) returns the unread part as
     * unresolved, so the group raises BW2004 and its literals are kept as candidates.
     *
     * @return array{source: string, label: string, result: ClassExpressionResult, bag: bool}|null
     */
    private function helper(string $expression): ?array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize('<?php '.$expression.';'),
            static fn (PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        array_pop($tokens);

        if ($tokens === []) {
            return null;
        }

        if (count($tokens) === 1 && $tokens[0]->is(T_VARIABLE) && $tokens[0]->text === '$attributes') {
            return ['source' => ClassGroup::SOURCE_ATTRIBUTES, 'label' => '$attributes', 'result' => ClassExpressionResult::empty(), 'bag' => true];
        }

        $isAttributes = $tokens[0]->is(T_VARIABLE) && $tokens[0]->text === '$attributes'
            && isset($tokens[1], $tokens[2], $tokens[3]) && $tokens[1]->is(T_OBJECT_OPERATOR) && $tokens[2]->is(T_STRING) && $tokens[3]->is('(');

        if ($isAttributes && $tokens[2]->text === 'class') {
            $result = self::withRemainder(ConditionalHelper::parse($this->firstArgument($expression, $tokens, 3)), $this->remainderAfterCall($expression, $tokens, 3));

            return ['source' => ClassGroup::SOURCE_ATTRIBUTES_CLASS, 'label' => '$attributes->class', 'result' => $result, 'bag' => true];
        }

        if ($isAttributes && $tokens[2]->text === 'merge') {
            $result = self::withRemainder(ConditionalHelper::mergeClasses($this->firstArgument($expression, $tokens, 3)), $this->remainderAfterCall($expression, $tokens, 3));

            return ['source' => ClassGroup::SOURCE_ATTRIBUTES_MERGE, 'label' => '$attributes->merge', 'result' => $result, 'bag' => true];
        }

        if ($tokens[0]->is(T_VARIABLE) && $tokens[0]->text === '$attributes') {
            return ['source' => ClassGroup::SOURCE_ATTRIBUTES, 'label' => '$attributes', 'result' => new ClassExpressionResult([], [], [$expression]), 'bag' => true];
        }

        $isArr = ($tokens[0]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && self::isArrName($tokens[0]->text))
            && isset($tokens[1], $tokens[2], $tokens[3]) && $tokens[1]->is(T_DOUBLE_COLON) && $tokens[2]->is(T_STRING) && $tokens[2]->text === 'toCssClasses' && $tokens[3]->is('(');

        if ($isArr) {
            return ['source' => ClassGroup::SOURCE_TO_CSS_CLASSES, 'label' => 'Arr::toCssClasses', 'result' => ConditionalHelper::parse($this->firstArgument($expression, $tokens, 3)), 'bag' => false];
        }

        return null;
    }

    /**
     * True for `Arr` itself or any name ending in `\Arr`, never a name that merely ends in the
     * letters "Arr" such as `FooArr`.
     */
    private static function isArrName(string $name): bool
    {
        return $name === 'Arr' || str_ends_with($name, '\Arr');
    }

    /**
     * $result with $remainder, the part of a chain past the call that was read, as one more
     * unresolved part; $result itself when nothing follows the call.
     */
    private static function withRemainder(ClassExpressionResult $result, ?string $remainder): ClassExpressionResult
    {
        if ($remainder === null) {
            return $result;
        }

        return new ClassExpressionResult($result->staticTokens, $result->conditions, [...$result->unresolved, $remainder]);
    }

    /**
     * The text of $expression after the call whose opening parenthesis is at $openIndex
     * (`->merge(['class' => 'p-17'])` for `$attributes->class(['p-16'])->merge(['class' => 'p-17'])`),
     * or null when the call ends the expression or never closes.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function remainderAfterCall(string $expression, array $tokens, int $openIndex): ?string
    {
        $depth = 0;

        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(['(', '[', '{'])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;

                if ($depth === 0) {
                    $remainder = trim(substr($expression, $token->pos + strlen($token->text) - strlen('<?php ')));

                    return $remainder === '' ? null : $remainder;
                }
            }
        }

        return null;
    }

    /**
     * The first argument of the call whose opening parenthesis token sits at $openIndex, found by
     * walking the token stream (so brackets inside a string literal, which is a single token,
     * never throw off the depth count) rather than scanning raw characters.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function firstArgument(string $expression, array $tokens, int $openIndex): ?Argument
    {
        $depth = 0;

        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(['(', '[', '{'])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;

                if ($depth === 0) {
                    $start = $tokens[$openIndex]->pos - strlen('<?php ');
                    $end = $token->pos + strlen($token->text) - strlen('<?php ');

                    return DirectiveArguments::split(substr($expression, $start, $end - $start))[0] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{source: string, label: string, result: ClassExpressionResult, bag: bool}  $helper
     */
    private function helperGroup(array $helper, string $tag, string $on, Position $position): void
    {
        $result = $helper['result'];

        $this->groups[] = new ClassGroup(
            $on, $tag, $helper['source'], self::classify($result),
            $result->staticTokens, $result->conditions, $result->unresolved, $helper['bag'],
            $position->startLine, $position->startColumn,
        );

        foreach ($result->unresolved as $expression) {
            $kind = $helper['source'] === ClassGroup::SOURCE_CLASS_DIRECTIVE ? UnresolvedConstruct::KIND_CLASS_DIRECTIVE : UnresolvedConstruct::KIND_HELPER;
            $this->unresolved[] = new UnresolvedConstruct($kind, $expression, $position->startLine, $position->startColumn);
            $this->diagnostics[] = Codes::make(Codes::HELPER_ELEMENT_UNREADABLE, ['helper' => $helper['label'], 'expression' => $expression], $position->startLine, $position->startColumn);
        }

        $this->candidatesFrom($helper['source'] === ClassGroup::SOURCE_CLASS_DIRECTIVE ? 'class-directive' : 'helper', $result->unresolved, CandidateScanner::PHP, $position);
    }

    /**
     * A class-attribute or php-bound group; unresolved parts raise one BW2001 per group.
     */
    private function group(string $source, string $on, string $tag, ClassExpressionResult $result, bool $bag, Position $position, string $kind): void
    {
        $this->groups[] = new ClassGroup(
            $on, $tag, $source, self::classify($result),
            $result->staticTokens, $result->conditions, $result->unresolved, $bag,
            $position->startLine, $position->startColumn,
        );

        if ($result->unresolved === []) {
            return;
        }

        $expression = implode(', ', $result->unresolved);
        $this->unresolved[] = new UnresolvedConstruct($kind, $expression, $position->startLine, $position->startColumn);
        $this->diagnostics[] = Codes::make(Codes::CLASS_EXPRESSION_UNRESOLVED, ['tag' => $tag, 'expression' => $expression], $position->startLine, $position->startColumn);
        $this->candidatesFrom('class-attribute', $result->unresolved, CandidateScanner::PHP, $position);
    }

    private function adapters(Element|ComponentTag $node, string $tag): void
    {
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->attributes($node) as $runtime) {
                $this->runtime[] = $runtime;

                if ($runtime->unresolved === []) {
                    continue;
                }

                $language = $runtime->adapter === RuntimeClasses::ADAPTER_ALPINE ? CandidateScanner::JS : CandidateScanner::PHP;
                $expression = implode(', ', $runtime->unresolved);
                $candidates = CandidateScanner::scan(implode(' ', $runtime->unresolved), $language);

                $this->unresolved[] = new UnresolvedConstruct($runtime->adapter, $expression, $runtime->line, $runtime->column);

                if ($candidates !== []) {
                    $this->candidates[] = new CandidateSet($runtime->adapter, $candidates, $runtime->line, $runtime->column);
                }

                $this->diagnostics[] = $runtime->adapter === RuntimeClasses::ADAPTER_ALPINE
                    ? Codes::make(Codes::ALPINE_BINDING_UNRESOLVED, ['attribute' => $runtime->attribute, 'tag' => $tag, 'expression' => $expression, 'candidates' => $candidates === [] ? 'none' : implode(', ', $candidates)], $runtime->line, $runtime->column)
                    : Codes::make(Codes::LIVEWIRE_CLASS_DYNAMIC, ['attribute' => $runtime->attribute, 'tag' => $tag, 'expression' => $expression], $runtime->line, $runtime->column);
            }
        }

        $this->alpineAttributeCandidates($node);
    }

    /**
     * Keeps every string in the Alpine attributes the adapters do not read (`x-data`, `x-init`,
     * object `x-bind`, `x-on:*`/`@*`, `x-effect`, other bound attributes) as a candidate. Classes
     * set from there (`$el.classList.add('p-2')`, a `:class` reading `x-data`) cannot be enumerated,
     * and a candidate that is not a class costs at most one extra rule.
     */
    private function alpineAttributeCandidates(Element|ComponentTag $node): void
    {
        $sources = [];

        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = (string) $attribute->value;

            if ($value === '') {
                continue;
            }

            $alpine = match ($attribute->kind) {
                // `:class` on an element and `x-bind:class` anywhere are the adapter's; so are the
                // transition lists, which are enumerated outright.
                // Forte reads `@click="..."` as a Blade construct followed by a nameless static
                // attribute holding the value, so an empty name is the `@event` shorthand.
                BindingKind::Static => ($name === '' || str_starts_with($name, 'x-') || str_starts_with($name, '@')) && $name !== 'x-bind:class' && ! str_starts_with($name, 'x-transition'),
                BindingKind::Bound => $node instanceof Element && $name !== 'class',
                BindingKind::Escaped => $node instanceof ComponentTag && $name !== 'class',
                default => false,
            };

            if ($alpine) {
                $sources[] = $value;
            }
        }

        $this->candidatesFrom('alpine-attribute', $sources, CandidateScanner::JS, $node->position);
    }

    private function phpBlock(PhpBlock $block): void
    {
        $inner = (string) preg_replace('/^@php\b|@endphp$/', '', trim($block->content));
        $candidates = CandidateScanner::scan($inner, CandidateScanner::PHP);

        if ($candidates !== []) {
            $this->candidates[] = new CandidateSet('php-block', $candidates, $block->position->startLine, $block->position->startColumn);
        }
    }

    /**
     * @param  list<string>  $expressions
     */
    private function candidatesFrom(string $origin, array $expressions, string $language, Position $position): void
    {
        if ($expressions === []) {
            return;
        }

        $candidates = CandidateScanner::scan(implode(' ', $expressions), $language);

        if ($candidates !== []) {
            $this->candidates[] = new CandidateSet($origin, $candidates, $position->startLine, $position->startColumn);
        }
    }

    private static function classify(ClassExpressionResult $result): GroupClassification
    {
        $hasTokens = $result->staticTokens !== [] || $result->conditions !== [];

        return match (true) {
            $hasTokens && $result->unresolved !== [] => GroupClassification::Mixed,
            $result->unresolved !== [] => GroupClassification::Unresolved,
            $result->conditions !== [] => GroupClassification::Enumerable,
            default => GroupClassification::Static,
        };
    }

    private static function inner(string $echo): string
    {
        $echo = trim($echo);

        if (str_starts_with($echo, '{!!')) {
            return trim(substr($echo, 3, -3));
        }

        if (str_starts_with($echo, '{{')) {
            return trim(substr($echo, 2, -2));
        }

        return $echo;
    }

    /**
     * Stable sort by line, then column, then discovery order.
     *
     * @template T of object{line: int, column: int}
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function sorted(array $items): array
    {
        $indexed = [];

        foreach ($items as $index => $item) {
            $indexed[] = [$item->line, $item->column, $index, $item];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): object => $row[3], $indexed);
    }
}
