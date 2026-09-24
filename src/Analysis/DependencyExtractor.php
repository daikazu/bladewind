<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Analysis;

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Diagnostic;
use Daikazu\BladeWind\Manifest\Dependency;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Parsing\Nodes\BindingKind;
use Daikazu\BladeWind\Parsing\Nodes\ComponentTag;
use Daikazu\BladeWind\Parsing\Nodes\Directive;
use Daikazu\BladeWind\Parsing\Nodes\Node;
use Daikazu\BladeWind\Parsing\Nodes\Position;
use Daikazu\BladeWind\Parsing\Nodes\Template;
use Daikazu\BladeWind\Resolution\Argument;
use Daikazu\BladeWind\Resolution\ArgumentKind;
use Daikazu\BladeWind\Resolution\ComponentResolver;
use Daikazu\BladeWind\Resolution\DirectiveArguments;
use Daikazu\BladeWind\Resolution\ResolutionKind;
use Daikazu\BladeWind\Resolution\ViewResolver;

final class DependencyExtractor
{
    private const COMPONENT_PREFIXES = ['x-', 'x:'];

    private const LIVEWIRE_PREFIX = 'livewire:';

    /**
     * Directive name => [dependency type, index of the target argument, whether a missing view is expected].
     *
     * Names are lowercase because the parser normalizes directive names to lowercase
     * (e.g. `@includeIf` is reported as `includeif`).
     *
     * @var array<string, array{DependencyType, int, bool}>
     */
    private const DIRECTIVES = [
        'include' => [DependencyType::Include, 0, false],
        'includeif' => [DependencyType::Include, 0, true],
        'includewhen' => [DependencyType::Include, 1, false],
        'includeunless' => [DependencyType::Include, 1, false],
        'extends' => [DependencyType::Extends, 0, false],
        'component' => [DependencyType::LegacyComponent, 0, false],
        'livewire' => [DependencyType::Livewire, 0, false],
    ];

    /**
     * @var list<Dependency>
     */
    private array $dependencies = [];

    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    /**
     * @var list<string>
     */
    private array $declaredDynamicTargets = [];

    public function __construct(
        private ComponentResolver $components,
        private ViewResolver $views,
    ) {}

    /**
     * @param  list<string>  $declaredDynamicTargets
     */
    public function extract(Template $template, array $declaredDynamicTargets = []): Extraction
    {
        $this->dependencies = [];
        $this->diagnostics = [];
        $this->declaredDynamicTargets = $declaredDynamicTargets;

        $template->walk(function (Node $node): void {
            if ($node instanceof ComponentTag) {
                $this->componentTag($node);
            } elseif ($node instanceof Directive) {
                $this->directive($node);
            }
        });

        return new Extraction($this->dependencies, $this->diagnostics);
    }

    private function componentTag(ComponentTag $tag): void
    {
        if ($tag->isSlot) {
            return;
        }

        if ($tag->prefix === self::LIVEWIRE_PREFIX) {
            $this->add(DependencyType::Livewire, $tag->name, Resolution::Static, null, null, $tag->position);

            return;
        }

        if (! in_array($tag->prefix, self::COMPONENT_PREFIXES, true)) {
            return;
        }

        if ($tag->name === 'dynamic-component') {
            $this->dynamicComponent($tag);

            return;
        }

        $this->resolveComponent(DependencyType::Component, $tag->name, $tag->position);
    }

    private function dynamicComponent(ComponentTag $tag): void
    {
        $attribute = $tag->attribute('component');

        if ($attribute === null || $attribute->kind !== BindingKind::Static || $attribute->containsEchoes || $attribute->value === null) {
            if ($this->declaredDynamicTargets !== []) {
                foreach ($this->declaredDynamicTargets as $target) {
                    $this->declaredComponent($target, $tag->position);
                }

                return;
            }

            $detail = $attribute === null ? ' (no component attribute)' : ' (bound expression: '.($attribute->value ?? '').')';

            $this->add(DependencyType::DynamicComponent, null, Resolution::Unresolved, null, null, $tag->position);
            $this->diagnose(Codes::DYNAMIC_COMPONENT_TARGET, ['detail' => $detail], $tag->position);

            return;
        }

        $this->resolveComponent(DependencyType::DynamicComponent, $attribute->value, $tag->position);
    }

    /**
     * A target listed in bladewind.components.<view>.dynamic. Resolution is
     * "declared" whatever the outcome; an unresolvable target keeps a null path and BW1003.
     */
    private function declaredComponent(string $target, Position $position): void
    {
        $resolved = $this->components->resolve($target);

        if ($resolved->kind === ResolutionKind::View) {
            $this->add(DependencyType::DynamicComponent, $target, Resolution::Declared, $resolved->path, null, $position);

            return;
        }

        if ($resolved->kind === ResolutionKind::ClassComponent) {
            $this->add(DependencyType::DynamicComponent, $target, Resolution::Declared, null, (string) $resolved->class, $position);
            $this->diagnose(Codes::CLASS_COMPONENT_VIEW_UNKNOWN, ['class' => (string) $resolved->class], $position);

            return;
        }

        $this->add(DependencyType::DynamicComponent, $target, Resolution::Declared, null, null, $position);
        $this->diagnose(Codes::COMPONENT_NOT_FOUND, ['target' => $target], $position);
    }

    private function resolveComponent(DependencyType $type, string $name, Position $position): void
    {
        $resolved = $this->components->resolve($name);

        if ($resolved->kind === ResolutionKind::View) {
            $this->add($type, $name, Resolution::Static, $resolved->path, null, $position);
        } elseif ($resolved->kind === ResolutionKind::ClassComponent) {
            $this->classComponent($type, $name, (string) $resolved->class, $position);
        } else {
            $this->unresolvedComponent($type, $name, $position);
        }
    }

    private function classComponent(DependencyType $type, string $name, string $class, Position $position): void
    {
        $this->add($type, $name, Resolution::Static, null, $class, $position);
        $this->diagnose(Codes::CLASS_COMPONENT_VIEW_UNKNOWN, ['class' => $class], $position);
    }

    private function unresolvedComponent(DependencyType $type, string $name, Position $position): void
    {
        $this->add($type, $name, Resolution::Unresolved, null, null, $position);
        $this->diagnose(Codes::COMPONENT_NOT_FOUND, ['target' => $name], $position);
    }

    private function directive(Directive $directive): void
    {
        $arguments = DirectiveArguments::split($directive->arguments);

        if (isset(self::DIRECTIVES[$directive->name])) {
            [$type, $index, $optional] = self::DIRECTIVES[$directive->name];

            $this->target($directive->name, $type, $arguments[$index] ?? null, $optional, $directive->position);

            return;
        }

        if ($directive->name === 'includefirst') {
            $first = $arguments[0] ?? null;

            if ($first === null || $first->kind !== ArgumentKind::ArrayLiteral) {
                $this->target($directive->name, DependencyType::IncludeFirst, $first, false, $directive->position);

                return;
            }

            $this->includeFirstCandidates($directive->name, $first->elements, $directive->position);

            return;
        }

        if ($directive->name === 'each') {
            $this->target($directive->name, DependencyType::Each, $arguments[0] ?? null, false, $directive->position);

            if (isset($arguments[3]) && ! $this->isRawLiteral($arguments[3])) {
                $this->target($directive->name, DependencyType::Each, $arguments[3], false, $directive->position);
            }
        }
    }

    /**
     * Each literal candidate resolves independently and never raises its own diagnostic
     * (that is the point of `@includeFirst`). Exactly one BW1002 is raised for the whole
     * directive, positioned at the directive, when no literal candidate resolved and there
     * is no expression candidate that could still resolve at runtime.
     *
     * @param  list<Argument>  $elements
     */
    private function includeFirstCandidates(string $directive, array $elements, Position $position): void
    {
        $literalTargets = [];
        $hasExpression = false;
        $resolved = false;

        foreach ($elements as $element) {
            if (! $element->isLiteral()) {
                $hasExpression = true;
                $this->add(DependencyType::IncludeFirst, null, Resolution::Unresolved, null, null, $position);
                $this->diagnose(Codes::UNRESOLVED_DIRECTIVE_TARGET, ['directive' => $directive], $position);

                continue;
            }

            $name = (string) $element->value;
            $literalTargets[] = $name;
            $path = $this->views->path($name);

            $this->add(DependencyType::IncludeFirst, $name, Resolution::Static, $path, null, $position);

            if ($path !== null) {
                $resolved = true;
            }
        }

        if (! $resolved && ! $hasExpression && $literalTargets !== []) {
            $this->diagnose(Codes::VIEW_NOT_FOUND, ['target' => implode(', ', $literalTargets), 'directive' => $directive], $position);
        }
    }

    private function isRawLiteral(Argument $argument): bool
    {
        return $argument->isLiteral() && str_starts_with((string) $argument->value, 'raw|');
    }

    private function target(string $directive, DependencyType $type, ?Argument $argument, bool $optional, Position $position): void
    {
        if ($argument === null || ! $argument->isLiteral()) {
            $this->add($type, null, Resolution::Unresolved, null, null, $position);
            $this->diagnose(Codes::UNRESOLVED_DIRECTIVE_TARGET, ['directive' => $directive], $position);

            return;
        }

        $name = (string) $argument->value;

        if ($type === DependencyType::Livewire) {
            $this->add($type, $name, Resolution::Static, null, null, $position);

            return;
        }

        $path = $this->views->path($name);

        $this->add($type, $name, Resolution::Static, $path, null, $position);

        if ($path === null && ! $optional) {
            $this->diagnose(Codes::VIEW_NOT_FOUND, ['target' => $name, 'directive' => $directive], $position);
        }
    }

    private function add(DependencyType $type, ?string $target, Resolution $resolution, ?string $path, ?string $class, Position $position): void
    {
        $this->dependencies[] = new Dependency($type, $target, $resolution, $path, $class, $position->startLine, $position->startColumn);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function diagnose(string $code, array $replacements, Position $position): void
    {
        $this->diagnostics[] = Codes::make($code, $replacements, $position->startLine, $position->startColumn);
    }
}
