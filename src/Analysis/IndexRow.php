<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

final readonly class IndexRow
{
    public function __construct(
        public int $static,
        public int $enumerable,
        public int $runtime,
        public int $candidate,
        public bool $declared,
        public bool $safelisted,
    ) {}

    /**
     * @return array{static: int, enumerable: int, runtime: int, candidate: int, declared: bool, safelisted: bool}
     */
    public function toArray(): array
    {
        return [
            'static' => $this->static,
            'enumerable' => $this->enumerable,
            'runtime' => $this->runtime,
            'candidate' => $this->candidate,
            'declared' => $this->declared,
            'safelisted' => $this->safelisted,
        ];
    }

    /**
     * @param  array{static: int, enumerable: int, runtime: int, candidate: int, declared: bool, safelisted: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['static'], $data['enumerable'], $data['runtime'], $data['candidate'], $data['declared'], $data['safelisted']);
    }
}
