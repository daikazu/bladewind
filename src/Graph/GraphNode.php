<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Graph;

final readonly class GraphNode
{
    public const KIND_VIEW = 'view';

    public const KIND_COMPONENT = 'component';

    public const KIND_CLASS_COMPONENT = 'class-component';

    public const KIND_LIVEWIRE = 'livewire';

    /**
     * @param  list<string>  $dependencies
     * @param  list<string>  $dependents
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $path,
        public ?string $name,
        public array $dependencies,
        public array $dependents,
        public int $unresolved,
        public int $declared,
    ) {}

    /**
     * @return array{kind: string, path: string|null, name: string|null, dependencies: list<string>, dependents: list<string>, unresolved: int, declared: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'path' => $this->path,
            'name' => $this->name,
            'dependencies' => $this->dependencies,
            'dependents' => $this->dependents,
            'unresolved' => $this->unresolved,
            'declared' => $this->declared,
        ];
    }

    /**
     * @param  array{kind: string, path: string|null, name: string|null, dependencies: list<string>, dependents: list<string>, unresolved: int, declared: int}  $data
     */
    public static function fromArray(string $id, array $data): self
    {
        return new self($id, $data['kind'], $data['path'], $data['name'], $data['dependencies'], $data['dependents'], $data['unresolved'], $data['declared']);
    }
}
