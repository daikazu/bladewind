<?php

declare(strict_types=1);

use Daikazu\BladeWind\Integration\PathFilter;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bw-filter-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/views/nested', 0777, true);
    mkdir($this->root.'/other', 0777, true);
    touch($this->root.'/views/nested/a.blade.php');
    touch($this->root.'/other/b.blade.php');
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->root);
});

it('normalises roots and drops missing ones', function (): void {
    $filter = new PathFilter([$this->root.'/views/../views', $this->root.'/missing']);

    expect($filter->roots())->toBe([realpath($this->root.'/views')]);
});

it('finds the root that contains a path', function (): void {
    $filter = new PathFilter([$this->root.'/views']);

    expect($filter->rootFor($this->root.'/views/nested/a.blade.php'))->toBe(realpath($this->root.'/views'))
        ->and($filter->contains($this->root.'/other/b.blade.php'))->toBeFalse()
        ->and($filter->contains(''))->toBeFalse();
});

it('does not treat a sibling directory with a shared prefix as inside a root', function (): void {
    mkdir($this->root.'/views-extra');
    touch($this->root.'/views-extra/c.blade.php');

    $filter = new PathFilter([$this->root.'/views']);

    expect($filter->contains($this->root.'/views-extra/c.blade.php'))->toBeFalse();
});
