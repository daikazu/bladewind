<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

final readonly class Dependency
{
    public function __construct(
        public DependencyType $type,
        public ?string $target,
        public Resolution $resolution,
        public ?string $resolvedPath,
        public ?string $resolvedClass,
        public int $line,
        public int $column,
    ) {}

    /**
     * @return array{type: string, target: string|null, resolution: string, resolved_path: string|null, resolved_class: string|null, line: int, column: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'target' => $this->target,
            'resolution' => $this->resolution->value,
            'resolved_path' => $this->resolvedPath,
            'resolved_class' => $this->resolvedClass,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }

    /**
     * @param  array{type: string, target: string|null, resolution: string, resolved_path: string|null, resolved_class: string|null, line: int, column: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            DependencyType::from($data['type']),
            $data['target'],
            Resolution::from($data['resolution']),
            $data['resolved_path'],
            $data['resolved_class'],
            $data['line'],
            $data['column'],
        );
    }

    public static function compare(self $a, self $b): int
    {
        return [$a->line, $a->column, $a->type->value, (string) $a->target]
            <=> [$b->line, $b->column, $b->type->value, (string) $b->target];
    }
}
