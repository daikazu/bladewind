<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class ConditionalTokens
{
    /**
     * @param  list<string>  $tokens
     */
    public function __construct(public array $tokens, public string $condition) {}

    /**
     * @return array{tokens: list<string>, condition: string}
     */
    public function toArray(): array
    {
        return ['tokens' => $this->tokens, 'condition' => $this->condition];
    }

    /**
     * @param  array{tokens: list<string>, condition: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['tokens'], $data['condition']);
    }
}
