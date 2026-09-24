<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Classes;

final readonly class UnresolvedConstruct
{
    public const KIND_CLASS_ATTRIBUTE = 'class-attribute';

    public const KIND_CLASS_DIRECTIVE = 'class-directive';

    public const KIND_HELPER = 'helper';

    public const KIND_ALPINE = 'alpine';

    public const KIND_LIVEWIRE = 'livewire';

    public const KIND_PHP_BOUND_CLASS = 'php-bound-class';

    public function __construct(public string $kind, public string $expression, public int $line, public int $column) {}

    /**
     * @return array{kind: string, expression: string, line: int, column: int}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'expression' => $this->expression, 'line' => $this->line, 'column' => $this->column];
    }

    /**
     * @param  array{kind: string, expression: string, line: int, column: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['kind'], $data['expression'], $data['line'], $data['column']);
    }
}
