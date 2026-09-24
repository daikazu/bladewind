<?php

declare(strict_types=1);

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Discovery\ViewKind;
use Daikazu\BladeWind\Manifest\ClassesBlock;
use Daikazu\BladeWind\Manifest\Dependency;
use Daikazu\BladeWind\Manifest\DependencyType;
use Daikazu\BladeWind\Manifest\Fingerprint;
use Daikazu\BladeWind\Manifest\Resolution;
use Daikazu\BladeWind\Manifest\ViewEntry;

function sampleEntry(): ViewEntry
{
    return new ViewEntry(
        path: '/app/resources/views/pages/home.blade.php',
        relativePath: 'resources/views/pages/home.blade.php',
        name: 'pages.home',
        kind: ViewKind::View,
        fingerprint: new Fingerprint('abc', 10, 1),
        compatHash: 'compat',
        dependencies: [
            new Dependency(DependencyType::Include, 'partials.footer', Resolution::Static, '/app/f.blade.php', null, 9, 1),
            new Dependency(DependencyType::Component, 'card', Resolution::Static, '/app/c.blade.php', null, 3, 5),
            new Dependency(DependencyType::Component, 'button', Resolution::Static, '/app/b.blade.php', null, 3, 5),
        ],
        classes: ClassesBlock::empty(),
        diagnostics: [
            Codes::make(Codes::VIEW_NOT_FOUND, ['target' => 'x', 'directive' => 'include'], 12, 3),
            Codes::make(Codes::CLASS_COMPONENT_VIEW_UNKNOWN, ['class' => 'A'], 4, 1),
        ],
    );
}

it('serialises with keys in a stable order and sorts dependencies and diagnostics', function (): void {
    $array = sampleEntry()->toArray();

    expect(array_keys($array))->toBe([
        'schema', 'path', 'relative_path', 'name', 'kind', 'fingerprint', 'compat_hash',
        'dependencies', 'classes', 'diagnostics',
    ])
        ->and($array['schema'])->toBe(4)
        ->and($array['kind'])->toBe('view')
        ->and(array_keys($array['dependencies'][0]))->toBe([
            'type', 'target', 'resolution', 'resolved_path', 'resolved_class', 'line', 'column',
        ])
        ->and(array_column($array['dependencies'], 'target'))->toBe(['button', 'card', 'partials.footer'])
        ->and(array_column($array['diagnostics'], 'code'))->toBe(['BW1005', 'BW1002']);
});

it('has schema 4', function (): void {
    expect(ViewEntry::SCHEMA)->toBe(4)
        ->and(sampleEntry()->toArray()['schema'])->toBe(4);
});

it('round-trips through arrays and JSON', function (): void {
    $entry = sampleEntry();

    expect(ViewEntry::fromArray($entry->toArray()))->toEqual(ViewEntry::fromArray(json_decode($entry->toJson(), true)))
        ->and($entry->toJson())->toEndWith("\n")
        ->and($entry->toJson())->toContain('"resolved_path": "/app/f.blade.php"');
});
