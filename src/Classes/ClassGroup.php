<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class ClassGroup
{
    public const ON_ELEMENT = 'element';

    public const ON_COMPONENT = 'component';

    public const SOURCE_ATTRIBUTE = 'attribute';

    public const SOURCE_CLASS_DIRECTIVE = 'class-directive';

    public const SOURCE_ATTRIBUTES_CLASS = 'attributes-class';

    public const SOURCE_ATTRIBUTES_MERGE = 'attributes-merge';

    public const SOURCE_TO_CSS_CLASSES = 'to-css-classes';

    public const SOURCE_ATTRIBUTES = 'attributes';

    /**
     * @param  list<string>  $tokens
     * @param  list<ConditionalTokens>  $conditional
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public string $on,
        public string $tag,
        public string $source,
        public GroupClassification $classification,
        public array $tokens,
        public array $conditional,
        public array $unresolved,
        public bool $bag,
        public int $line,
        public int $column,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'on' => $this->on,
            'tag' => $this->tag,
            'source' => $this->source,
            'classification' => $this->classification->value,
            'tokens' => $this->tokens,
            'conditional' => array_map(static fn (ConditionalTokens $c): array => $c->toArray(), $this->conditional),
            'unresolved' => $this->unresolved,
            'bag' => $this->bag,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    /**
     * @param  array{on: string, tag: string, source: string, classification: string, tokens: list<string>, conditional: list<array{tokens: list<string>, condition: string}>, unresolved: list<string>, bag: bool, line: int, column: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['on'],
            $data['tag'],
            $data['source'],
            GroupClassification::from($data['classification']),
            $data['tokens'],
            array_map(static fn (array $c): ConditionalTokens => ConditionalTokens::fromArray($c), $data['conditional']),
            $data['unresolved'],
            $data['bag'],
            $data['line'],
            $data['column'],
        );
    }
}
