<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class RuntimeClasses
{
    public const ADAPTER_LIVEWIRE = 'livewire';

    public const ADAPTER_ALPINE = 'alpine';

    /**
     * @param  list<string>  $tokens  Every token this binding can produce, unconditional and conditional alike.
     * @param  list<ConditionalTokens>  $conditional  The conditioned subset of $tokens, repeated here with its conditions.
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public string $adapter,
        public string $attribute,
        public RuntimeClassification $classification,
        public bool $remove,
        public array $tokens,
        public array $conditional,
        public array $unresolved,
        public int $line,
        public int $column,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'attribute' => $this->attribute,
            'classification' => $this->classification->value,
            'remove' => $this->remove,
            'tokens' => $this->tokens,
            'conditional' => array_map(static fn (ConditionalTokens $c): array => $c->toArray(), $this->conditional),
            'unresolved' => $this->unresolved,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    /**
     * @param  array{adapter: string, attribute: string, classification: string, remove: bool, tokens: list<string>, conditional: list<array{tokens: list<string>, condition: string}>, unresolved: list<string>, line: int, column: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['adapter'],
            $data['attribute'],
            RuntimeClassification::from($data['classification']),
            $data['remove'],
            $data['tokens'],
            array_map(static fn (array $c): ConditionalTokens => ConditionalTokens::fromArray($c), $data['conditional']),
            $data['unresolved'],
            $data['line'],
            $data['column'],
        );
    }
}
