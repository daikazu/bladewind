<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class CandidateSet
{
    /**
     * @param  list<string>  $tokens
     */
    public function __construct(public string $origin, public array $tokens, public int $line, public int $column) {}

    /**
     * @return array{origin: string, tokens: list<string>, line: int, column: int}
     */
    public function toArray(): array
    {
        return ['origin' => $this->origin, 'tokens' => $this->tokens, 'line' => $this->line, 'column' => $this->column];
    }

    /**
     * @param  array{origin: string, tokens: list<string>, line: int, column: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['origin'], $data['tokens'], $data['line'], $data['column']);
    }
}
