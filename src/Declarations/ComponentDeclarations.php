<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Declarations;

use Daikazu\BladeWind\Classes\Tokenizer;
use Daikazu\BladeWind\Resolution\ComponentResolver;
use Daikazu\BladeWind\Resolution\ResolutionKind;
use Daikazu\BladeWind\Resolution\ViewResolver;
use Illuminate\Contracts\Config\Repository;

/**
 * bladewind.components, resolved from component or view names to file paths once per process.
 */
final class ComponentDeclarations
{
    /**
     * @var array<string, array{classes: list<string>, dynamic: list<string>}>|null
     */
    private ?array $byPath = null;

    /**
     * @var list<string>
     */
    private array $unresolvedKeys = [];

    public function __construct(
        private Repository $config,
        private ComponentResolver $components,
        private ViewResolver $views,
    ) {}

    /**
     * @return list<string>
     */
    public function classesFor(string $path): array
    {
        return $this->load()[$path]['classes'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function dynamicTargetsFor(string $path): array
    {
        return $this->load()[$path]['dynamic'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function unresolvedKeys(): array
    {
        $this->load();

        return $this->unresolvedKeys;
    }

    /**
     * @return array<string, array{classes: list<string>, dynamic: list<string>}>
     */
    private function load(): array
    {
        if ($this->byPath !== null) {
            return $this->byPath;
        }

        /** @var array<string, mixed> $declared */
        $declared = $this->config->get('bladewind.components', []);
        $this->byPath = [];

        foreach ($declared as $key => $declaration) {
            $path = $this->resolve((string) $key);

            if ($path === null) {
                $this->unresolvedKeys[] = (string) $key;

                continue;
            }

            $classes = [];
            $dynamic = [];

            if (is_array($declaration)) {
                foreach ((array) ($declaration['classes'] ?? []) as $value) {
                    if (is_string($value)) {
                        $classes = [...$classes, ...Tokenizer::split($value)];
                    }
                }

                foreach ((array) ($declaration['dynamic'] ?? []) as $value) {
                    if (is_string($value) && $value !== '') {
                        $dynamic[] = $value;
                    }
                }
            }

            $this->byPath[$path] = [
                'classes' => [...($this->byPath[$path]['classes'] ?? []), ...$classes],
                'dynamic' => [...($this->byPath[$path]['dynamic'] ?? []), ...$dynamic],
            ];
        }

        return $this->byPath;
    }

    private function resolve(string $key): ?string
    {
        $resolved = $this->components->resolve($key);

        if ($resolved->kind === ResolutionKind::View && $resolved->path !== null) {
            return $resolved->path;
        }

        if ($resolved->kind === ResolutionKind::ClassComponent) {
            return null;
        }

        return $this->views->path($key);
    }
}
