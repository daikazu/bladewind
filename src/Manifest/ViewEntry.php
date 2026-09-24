<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Manifest;

use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Discovery\ViewKind;

final readonly class ViewEntry
{
    public const SCHEMA = 4;

    public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @var list<Dependency>
     */
    public array $dependencies;

    /**
     * @var list<Diagnostic>
     */
    public array $diagnostics;

    /**
     * @param  array<string|int, Dependency>  $dependencies
     * @param  array<string|int, Diagnostic>  $diagnostics
     */
    public function __construct(
        public string $path,
        public string $relativePath,
        public string $name,
        public ViewKind $kind,
        public Fingerprint $fingerprint,
        public string $compatHash,
        array $dependencies,
        public ClassesBlock $classes,
        array $diagnostics,
    ) {
        usort($dependencies, Dependency::compare(...));
        usort($diagnostics, static fn (Diagnostic $a, Diagnostic $b): int => [$a->line ?? 0, $a->column ?? 0, $a->code] <=> [$b->line ?? 0, $b->column ?? 0, $b->code]);

        $this->dependencies = $dependencies;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'path' => $this->path,
            'relative_path' => $this->relativePath,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'fingerprint' => $this->fingerprint->toArray(),
            'compat_hash' => $this->compatHash,
            'dependencies' => array_map(static fn (Dependency $d): array => $d->toArray(), $this->dependencies),
            'classes' => $this->classes->toArray(),
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), self::JSON_FLAGS)."\n";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{path: string, relative_path: string, name: string, kind: string, fingerprint: array{content: string, size: int, mtime: int}, compat_hash: string, dependencies: list<array{type: string, target: string|null, resolution: string, resolved_path: string|null, resolved_class: string|null, line: int, column: int}>, classes: array<string, mixed>, diagnostics: list<array{code: string, severity: string, message: string, line: int|null, column: int|null}>} $data */
        return new self(
            $data['path'],
            $data['relative_path'],
            $data['name'],
            ViewKind::from($data['kind']),
            Fingerprint::fromArray($data['fingerprint']),
            $data['compat_hash'],
            array_map(static fn (array $d): Dependency => Dependency::fromArray($d), $data['dependencies']),
            ClassesBlock::fromArray($data['classes']),
            array_map(static fn (array $d): Diagnostic => Diagnostic::fromArray($d), $data['diagnostics']),
        );
    }
}
