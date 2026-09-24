<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Stylesheets;

/**
 * One configured Vite stylesheet entry, resolved (or not) through the build manifest.
 */
final readonly class Stylesheet
{
    public function __construct(
        public string $name,
        public ?string $file,
        public ?string $hash,
        public int $bytes,
        public bool $found,
    ) {}

    /**
     * @return array{name: string, file: string|null, hash: string|null, bytes: int, found: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'file' => $this->file,
            'hash' => $this->hash,
            'bytes' => $this->bytes,
            'found' => $this->found,
        ];
    }
}
