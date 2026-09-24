<?php

declare(strict_types=1);

use Daikazu\BladeWind\Manifest\Fingerprint;

it('hashes content with xxh128 and records size and mtime from the file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'bw');
    file_put_contents($path, 'hello');
    touch($path, 1_700_000_000);

    $fingerprint = Fingerprint::fromSource('hello', $path);

    expect($fingerprint->content)->toBe(hash('xxh128', 'hello'))
        ->and($fingerprint->size)->toBe(5)
        ->and($fingerprint->mtime)->toBe(1_700_000_000)
        ->and(Fingerprint::fromArray($fingerprint->toArray()))->toEqual($fingerprint);

    unlink($path);
});

it('falls back to source length and zero mtime when the file is missing', function (): void {
    $fingerprint = Fingerprint::fromSource('abc', '/nowhere/missing.blade.php');

    expect($fingerprint->size)->toBe(3)->and($fingerprint->mtime)->toBe(0);
});

it('reports zero-byte file size accurately and prefers file mtime over source content', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'bw');
    file_put_contents($path, '');
    touch($path, 1_600_000_000);

    $fingerprint = Fingerprint::fromSource('', $path);

    expect($fingerprint->size)->toBe(0)->and($fingerprint->mtime)->toBe(1_600_000_000);

    // The file on disk wins even when the source is non-empty.
    $fingerprint2 = Fingerprint::fromSource('not-empty', $path);

    expect($fingerprint2->size)->toBe(0)->and($fingerprint2->mtime)->toBe(1_600_000_000);

    unlink($path);
});
