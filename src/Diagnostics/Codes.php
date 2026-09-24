<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Diagnostics;

use InvalidArgumentException;

final class Codes
{
    public const UNRESOLVED_DIRECTIVE_TARGET = 'BW1001';

    public const VIEW_NOT_FOUND = 'BW1002';

    public const COMPONENT_NOT_FOUND = 'BW1003';

    public const DYNAMIC_COMPONENT_TARGET = 'BW1004';

    public const CLASS_COMPONENT_VIEW_UNKNOWN = 'BW1005';

    public const PATH_SKIPPED = 'BW1006';

    public const ANALYSIS_FAILED = 'BW1008';

    public const CLASS_EXPRESSION_UNRESOLVED = 'BW2001';

    public const ALPINE_BINDING_UNRESOLVED = 'BW2002';

    public const LIVEWIRE_CLASS_DYNAMIC = 'BW2003';

    public const HELPER_ELEMENT_UNREADABLE = 'BW2004';

    public const DEPENDENCY_CYCLE = 'BW2005';

    public const DECLARATION_UNRESOLVED = 'BW2006';

    public const SAFELIST_ENTRY_INVALID = 'BW2007';

    public const STYLESHEET_NOT_FOUND = 'BW3001';

    public const STYLESHEET_NOT_CSS = 'BW3002';

    public const PAGE_STYLES_FALLBACK = 'BW6001';

    public const STYLESHEET_NOT_SPLITTABLE = 'BW6002';

    public const PAGE_VIEWS_UNANALYSED = 'BW6003';

    public const PAGE_STYLES_WRITE_FAILED = 'BW6004';

    public const STYLESHEET_SUPPORT_UNSHAKEN = 'BW6005';

    /**
     * @return array<string, array{severity: Severity, template: string}>
     */
    public static function registry(): array
    {
        return [
            self::UNRESOLVED_DIRECTIVE_TARGET => [
                'severity' => Severity::Warning,
                'template' => '@{directive} target is not a string literal and cannot be resolved statically. Use a literal view name so BladeWind can track it.',
            ],
            self::VIEW_NOT_FOUND => [
                'severity' => Severity::Warning,
                'template' => 'View [{target}] referenced by @{directive} was not found in any view path.',
            ],
            self::COMPONENT_NOT_FOUND => [
                'severity' => Severity::Warning,
                'template' => 'Component <x-{target}> could not be resolved to a class or a view.',
            ],
            self::DYNAMIC_COMPONENT_TARGET => [
                'severity' => Severity::Info,
                'template' => 'Dynamic component target is not statically known{detail}. Its styles fall back to the global strategy.',
            ],
            self::CLASS_COMPONENT_VIEW_UNKNOWN => [
                'severity' => Severity::Info,
                'template' => 'Class component {class} renders a view that is not statically known; its dependencies are not tracked yet.',
            ],
            self::PATH_SKIPPED => [
                'severity' => Severity::Info,
                'template' => '{detail}',
            ],
            self::ANALYSIS_FAILED => [
                'severity' => Severity::Error,
                'template' => 'Analysis failed: {message}',
            ],
            self::CLASS_EXPRESSION_UNRESOLVED => [
                'severity' => Severity::Info,
                'template' => 'Class attribute on <{tag}> contains an expression that cannot be enumerated: {expression}. Static tokens are kept; add the possible classes to the safelist or declare them for this view.',
            ],
            self::ALPINE_BINDING_UNRESOLVED => [
                'severity' => Severity::Info,
                'template' => 'Alpine binding {attribute} on <{tag}> is not statically enumerable: {expression}. Recorded candidates: {candidates}. Declare the classes it can produce if they are not among them.',
            ],
            self::LIVEWIRE_CLASS_DYNAMIC => [
                'severity' => Severity::Info,
                'template' => 'Livewire {attribute} on <{tag}> has a dynamic value: {expression}. Declare the classes it can produce.',
            ],
            self::HELPER_ELEMENT_UNREADABLE => [
                'severity' => Severity::Warning,
                'template' => '{helper} element {expression} is not a string literal or a \'classes\' => condition pair and was skipped.',
            ],
            self::DEPENDENCY_CYCLE => [
                'severity' => Severity::Info,
                'template' => 'Dependency cycle: {cycle}.',
            ],
            self::DECLARATION_UNRESOLVED => [
                'severity' => Severity::Warning,
                'template' => 'Declaration key [{key}] in bladewind.components does not resolve to a view or an anonymous component and was ignored.',
            ],
            self::SAFELIST_ENTRY_INVALID => [
                'severity' => Severity::Warning,
                'template' => 'Safelist entry [{entry}] is not an exact token or a prefix-* pattern and was ignored.',
            ],
            self::STYLESHEET_NOT_FOUND => [
                'severity' => Severity::Warning,
                'template' => 'Stylesheet [{name}] was not found through the Vite manifest at {path}.',
            ],
            self::STYLESHEET_NOT_CSS => [
                'severity' => Severity::Warning,
                'template' => 'Stylesheet entry [{name}] resolves to {file} through the Vite manifest, which is not a stylesheet; name the CSS entry itself (a script entry that imports CSS is not one).',
            ],
            self::PAGE_STYLES_FALLBACK => [
                'severity' => Severity::Warning,
                'template' => 'Page styles fell back to the full stylesheet: {reason}.',
            ],
            self::STYLESHEET_NOT_SPLITTABLE => [
                'severity' => Severity::Warning,
                'template' => 'Stylesheet {name} has no {detail}; page styles are disabled for it.',
            ],
            self::PAGE_VIEWS_UNANALYSED => [
                'severity' => Severity::Info,
                'template' => '{n} no analysis entry (outside bladewind.paths): {paths}. Add their directories to bladewind.paths; until then those pages get the full stylesheet, unless pages.unanalysed is "html", which builds them from their rendered classes alone.',
            ],
            self::PAGE_STYLES_WRITE_FAILED => [
                'severity' => Severity::Warning,
                'template' => 'Page stylesheet could not be written to {path}: {message}.',
            ],
            self::STYLESHEET_SUPPORT_UNSHAKEN => [
                'severity' => Severity::Info,
                'template' => 'Stylesheet {name} keeps its theme and properties layers whole: {reason}.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::registry());
    }

    /**
     * @param  array<string, string>  $replacements
     */
    public static function make(string $code, array $replacements = [], ?int $line = null, ?int $column = null): Diagnostic
    {
        $entry = self::registry()[$code] ?? null;

        if ($entry === null) {
            throw new InvalidArgumentException("Unknown diagnostic code [{$code}].");
        }

        $search = array_map(static fn (string $key): string => '{'.$key.'}', array_keys($replacements));
        $message = str_replace($search, array_values($replacements), $entry['template']);

        return new Diagnostic($code, $entry['severity'], $message, $line, $column);
    }
}
