<?php

declare(strict_types=1);

use Daikazu\BladeWind\BladeWind;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\URL;

it('names root and page files from the version-derived sheet id and the set hash prefix', function (): void {
    $store = app(PageStyleStore::class);
    $stylesheetHash = str_repeat('a', 32);
    $setHash = str_repeat('b', 32);
    $sheetId = $store->sheetId($stylesheetHash);

    // The version is in the id because what these files hold for one stylesheet is decided by this
    // version's emission rules: an upgrade must name new files rather than serve the old ones.
    expect($sheetId)->toBe(substr(hash('xxh128', $stylesheetHash."\n".BladeWind::VERSION), 0, 12))
        ->and($sheetId)->not->toBe(substr($stylesheetHash, 0, 12))
        ->and($store->rootFile($stylesheetHash))->toBe('bw-root-'.$sheetId.'.css')
        ->and($store->pageFile($stylesheetHash, $setHash))->toBe('bw-page-'.$sheetId.'-'.substr($setHash, 0, 12).'.css')
        // The whole stylesheet hash reaches the id, not just the twelve characters the name shows.
        ->and($store->sheetId($stylesheetHash))->not->toBe($store->sheetId(substr($stylesheetHash, 0, 12).str_repeat('c', 20)));
});

it('refuses a directory that is empty or climbs out of public, and normalises surrounding slashes', function (): void {
    $files = new Filesystem;

    foreach (['', '/', '//', '../outside', 'a/../../b', '..'] as $bad) {
        expect(fn () => new PageStyleStore($files, '/srv/public', $bad))->toThrow(InvalidArgumentException::class, 'bladewind.assets.path');
    }

    expect((new PageStyleStore($files, '/srv/public', '/bladewind/'))->directory())->toBe('/srv/public/bladewind')
        ->and((new PageStyleStore($files, '/srv/public', 'build/bw'))->directory())->toBe('/srv/public/build/bw');
});

it('prunes the files an earlier package version left behind', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();
    $sheet = str_repeat('a', 32);

    // What a version whose emission rules differ wrote for this very stylesheet: same hash, another
    // id, so the sheet-id comparison treats it exactly as it treats a rebuilt stylesheet's leftovers.
    $previous = 'bw-page-'.substr(hash('xxh128', $sheet."\n0.2.0"), 0, 12).'-'.str_repeat('1', 12).'.css';

    $store->write($previous, "old{1:1}\n");
    touch($directory.'/'.$previous, 1_700_000_000);
    $store->write($store->pageFile($sheet, str_repeat('2', 12)), "new{1:1}\n");

    expect($store->prune($sheet, 500))->toBe(1)
        ->and(file_exists($directory.'/'.$previous))->toBeFalse()
        ->and(file_exists($directory.'/'.$store->pageFile($sheet, str_repeat('2', 12))))->toBeTrue();
});

it('treats an empty file as absent and never writes one, so a truncated stylesheet is regenerated rather than linked', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();
    (new Filesystem)->ensureDirectoryExists($directory);

    // A page file another worker pruned while this one was about to touch() it comes back as zero
    // bytes; a full disk can leave one too. Neither is a stylesheet.
    file_put_contents($directory.'/bw-page-empty.css', '');

    expect($store->has('bw-page-empty.css'))->toBeFalse();

    $store->write('bw-page-empty.css', '.a{x:y}');

    expect((string) file_get_contents($directory.'/bw-page-empty.css'))->toBe('.a{x:y}');

    // A page whose class set selects no rule at all still gets a file with something in it.
    $store->write('bw-page-none.css', '');

    expect($store->has('bw-page-none.css'))->toBeTrue()
        ->and(filesize($directory.'/bw-page-none.css'))->toBeGreaterThan(0);
});

it('writes atomically and never rewrites a file that already exists', function (): void {
    $store = app(PageStyleStore::class);
    $file = $store->pageFile('sheetA', 'set1');
    $path = $store->directory().'/'.$file;

    $store->write($file, "a{b:c}\n");
    $mtimeBefore = filemtime($path);

    $store->write($file, "different{content:here}\n");

    expect(filemtime($path))->toBe($mtimeBefore)
        ->and(file_get_contents($path))->toBe("a{b:c}\n")
        ->and(glob($store->directory().'/*.tmp'))->toBe([]);
});

it('reads existence from the disk every time, for files created and removed out of band', function (): void {
    $store = app(PageStyleStore::class);
    $written = $store->pageFile('sheetA', 'set1');
    $outOfBand = $store->rootFile('sheetA');

    expect($store->has($written))->toBeFalse()
        ->and($store->has($outOfBand))->toBeFalse();

    $store->write($written, "a{b:c}\n");
    expect($store->has($written))->toBeTrue();

    (new Filesystem)->ensureDirectoryExists($store->directory());
    (new Filesystem)->put($store->directory().'/'.$outOfBand, "root{1:1}\n");

    expect($store->has($outOfBand))->toBeTrue();

    // Nothing is memoised: a file another worker pruned after this one wrote it stops existing
    // here too, so the next request regenerates it instead of linking a stylesheet that is gone.
    (new Filesystem)->delete($store->directory().'/'.$written);

    expect($store->has($written))->toBeFalse();
});

it('builds the public URL from the application origin, or from assets.url when set', function (): void {
    $store = app(PageStyleStore::class);
    $file = $store->rootFile('sheetA');

    expect($store->url($file))->toBe(url('/'.$this->assetsPath().'/'.$file));

    // asset() would follow ASSET_URL to a bucket filled at build time, which never receives a file
    // written while serving a request; only an origin-pull CDN can be asked for these.
    URL::useAssetOrigin('https://cdn.example.com/push');

    expect($store->url($file))->toBe(url('/'.$this->assetsPath().'/'.$file))
        ->and(asset($this->assetsPath().'/'.$file))->toBe('https://cdn.example.com/push/'.$this->assetsPath().'/'.$file);

    config()->set('bladewind.assets.url', 'https://pull.example.com/');
    app()->forgetInstance(PageStyleStore::class);

    expect(app(PageStyleStore::class)->url($file))->toBe('https://pull.example.com/'.$this->assetsPath().'/'.$file);
});

it('prunes other-sheet files and the oldest same-sheet page files beyond the cap', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();

    // Full-length (12-hex-prefix-shaped) placeholders, so the sheet-prefix comparison inside
    // prune() (which always reads exactly 12 characters after the "bw-root-"/"bw-page-" prefix)
    // behaves as it would against a real hash; a short literal like "sheetA" would let the
    // extracted prefix run into the ".css" suffix.
    $sheetA = str_repeat('a', 12);
    $sheetB = str_repeat('b', 12);
    $set1 = str_repeat('1', 12);
    $set2 = str_repeat('2', 12);
    $set3 = str_repeat('3', 12);
    $setB1 = str_repeat('c', 12);

    $store->write($store->rootFile($sheetA), "root-a{1:1}\n");
    $store->write($store->pageFile($sheetB, $setB1), "b1{1:1}\n");
    // Past the grace window, so the other-sheet rule applies (a fresh one is kept; see below).
    touch($directory.'/'.$store->pageFile($sheetB, $setB1), 1_700_000_000);

    $store->write($store->pageFile($sheetA, $set1), "a1{1:1}\n");
    touch($directory.'/'.$store->pageFile($sheetA, $set1), 1_700_000_000);

    $store->write($store->pageFile($sheetA, $set2), "a2{1:1}\n");
    touch($directory.'/'.$store->pageFile($sheetA, $set2), 1_700_000_100);

    $store->write($store->pageFile($sheetA, $set3), "a3{1:1}\n");
    touch($directory.'/'.$store->pageFile($sheetA, $set3), 1_700_000_200);

    (new Filesystem)->put($directory.'/bw-abc123456789.css', 'compressed');

    $deleted = $store->prune($sheetA, 2);

    expect($deleted)->toBe(2)
        ->and(file_exists($directory.'/'.$store->pageFile($sheetB, $setB1)))->toBeFalse()
        ->and(file_exists($directory.'/'.$store->pageFile($sheetA, $set1)))->toBeFalse()
        ->and(file_exists($directory.'/'.$store->pageFile($sheetA, $set2)))->toBeTrue()
        ->and(file_exists($directory.'/'.$store->pageFile($sheetA, $set3)))->toBeTrue()
        ->and(file_exists($directory.'/'.$store->rootFile($sheetA)))->toBeTrue()
        ->and(file_exists($directory.'/bw-abc123456789.css'))->toBeTrue();
});

it('prunes by the mtimes the files carry now, breaking a tie by filename', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();
    $sheet = str_repeat('a', 12);
    $pages = [
        $store->pageFile($sheet, str_repeat('1', 12)),
        $store->pageFile($sheet, str_repeat('2', 12)),
        $store->pageFile($sheet, str_repeat('3', 12)),
    ];

    foreach ($pages as $page) {
        $store->write($page, "page{1:1}\n");
    }

    // Two files written within the same second carry the same mtime, which is the resolution
    // filemtime() reports; the filename then decides which of them the cap keeps.
    touch($directory.'/'.$pages[0], 1_700_000_100);
    touch($directory.'/'.$pages[1], 1_700_000_100);
    touch($directory.'/'.$pages[2], 1_700_000_000);

    $deleted = $store->prune($sheet, 1);

    expect($deleted)->toBe(2)
        ->and(file_exists($directory.'/'.$pages[2]))->toBeFalse()
        ->and(file_exists($directory.'/'.$pages[0]))->toBeFalse()
        ->and(file_exists($directory.'/'.$pages[1]))->toBeTrue();
});

it('never prunes a file younger than the grace window, whichever rule would have deleted it', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();
    $sheet = str_repeat('a', 12);
    $other = str_repeat('b', 12);
    $fresh = $store->pageFile($sheet, str_repeat('1', 12));
    $stale = $store->pageFile($sheet, str_repeat('2', 12));
    $freshOtherSheet = $store->pageFile($other, str_repeat('3', 12));

    foreach ([$fresh, $stale, $freshOtherSheet] as $page) {
        $store->write($page, "page{1:1}\n");
    }

    touch($directory.'/'.$stale, time() - PageStyleStore::GRACE_SECONDS - 60);

    // Worker A has passed has() and is about to link $fresh while worker B prunes; the cap must not
    // take a file this recent, or A's response links a stylesheet that 404s.
    $deleted = $store->prune($sheet, 0);

    expect($deleted)->toBe(1)
        ->and(file_exists($directory.'/'.$stale))->toBeFalse()
        ->and(file_exists($directory.'/'.$fresh))->toBeTrue()
        ->and(file_exists($directory.'/'.$freshOtherSheet))->toBeTrue();
});

it('touches a page file on a hit only once it is older than the grace window', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();
    $page = $store->pageFile(str_repeat('a', 12), str_repeat('1', 12));

    $store->write($page, "page{1:1}\n");

    expect($store->touchIfStale($page))->toBeFalse()
        ->and($store->touchIfStale('bw-page-absent.css'))->toBeFalse();

    $old = time() - PageStyleStore::GRACE_SECONDS - 60;
    touch($directory.'/'.$page, $old);

    expect($store->touchIfStale($page))->toBeTrue();

    clearstatcache(true, $directory.'/'.$page);

    expect(filemtime($directory.'/'.$page))->toBeGreaterThan($old);
});

it('clears only root and page stylesheets, leaving unrelated files alone', function (): void {
    $store = app(PageStyleStore::class);
    $directory = $store->directory();

    $store->write($store->rootFile('sheetA'), "root{1:1}\n");
    $store->write($store->pageFile('sheetA', 'set1'), "page{1:1}\n");
    (new Filesystem)->put($directory.'/bw-abc123456789.css', 'compressed');

    $deleted = $store->clear();

    expect($deleted)->toBe(2)
        ->and(glob($directory.'/bw-root-*.css'))->toBe([])
        ->and(glob($directory.'/bw-page-*.css'))->toBe([])
        ->and(file_exists($directory.'/bw-abc123456789.css'))->toBeTrue();
});

it('deletes the temporary file and throws when the write comes up short', function (): void {
    // Renaming a truncated stylesheet into place would cache it for good: nothing rewrites a page
    // file that exists.
    $store = new PageStyleStore(shortWritingFilesystem(), $this->fixturePath('public'), $this->assetsPath());
    $directory = $store->directory();

    expect(fn (): mixed => $store->write($store->rootFile(str_repeat('a', 12)), "root{1:1}\n"))->toThrow(RuntimeException::class, 'Unable to write generated stylesheet')
        ->and(glob($directory.'/*.tmp'))->toBe([])
        ->and(glob($directory.'/bw-root-*.css'))->toBe([]);
});
