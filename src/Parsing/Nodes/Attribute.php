<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Parsing\Nodes;

final readonly class Attribute
{
    public const CONSTRUCT_ECHO = 'echo';

    /**
     * @param  list<string>|null  $staticTokens
     */
    public function __construct(
        public string $name,
        public BindingKind $kind,
        public ?string $value,
        public ?array $staticTokens,
        public bool $containsEchoes,
        public ?string $constructName = null,
        public ?string $constructArguments = null,
    ) {}

    /**
     * The attribute name as written, including the Blade binding prefix.
     */
    public function rawName(): string
    {
        return match ($this->kind) {
            BindingKind::Bound => ':'.$this->name,
            BindingKind::Escaped => '::'.$this->name,
            BindingKind::Shorthand => ':$'.$this->name,
            BindingKind::BladeConstruct => '',
            BindingKind::Static => $this->name,
        };
    }
}
