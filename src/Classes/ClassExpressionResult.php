<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class ClassExpressionResult
{
    /**
     * @param  list<string>  $staticTokens
     * @param  list<ConditionalTokens>  $conditions
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public array $staticTokens,
        public array $conditions,
        public array $unresolved,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function resolvedFully(): bool
    {
        return $this->unresolved === [];
    }

    public function isEmpty(): bool
    {
        return $this->staticTokens === [] && $this->conditions === [] && $this->unresolved === [];
    }

    /**
     * @return list<string>
     */
    public function enumerableTokens(): array
    {
        $tokens = [];

        foreach ($this->conditions as $condition) {
            $tokens = [...$tokens, ...$condition->tokens];
        }

        return $tokens;
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->staticTokens, ...$other->staticTokens],
            [...$this->conditions, ...$other->conditions],
            [...$this->unresolved, ...$other->unresolved],
        );
    }
}
