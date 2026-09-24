<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Resolution;

final readonly class Argument
{
    /**
     * @param  list<Argument>  $elements
     */
    public function __construct(
        public ArgumentKind $kind,
        public ?string $value,
        public array $elements,
        public string $source,
    ) {}

    public function isLiteral(): bool
    {
        return $this->kind === ArgumentKind::Literal && $this->value !== null;
    }
}
