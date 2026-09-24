<?php

declare(strict_types=1);

use Daikazu\BladeWind\Diagnostics\Codes;
use Daikazu\BladeWind\Diagnostics\Severity;

it('registers the page styles diagnostic codes', function (): void {
    expect(Codes::all())->toContain('BW6001', 'BW6002', 'BW6003', 'BW6004', 'BW6005')
        ->and(Codes::make(Codes::PAGE_STYLES_FALLBACK, ['reason' => 'manifest missing'])->message)->toBe('Page styles fell back to the full stylesheet: manifest missing.')
        ->and(Codes::make(Codes::PAGE_STYLES_FALLBACK, ['reason' => 'x'])->severity)->toBe(Severity::Warning)
        ->and(Codes::make(Codes::STYLESHEET_NOT_SPLITTABLE, ['name' => 'resources/css/app.css', 'detail' => 'top-level @layer utilities block'])->message)->toBe('Stylesheet resources/css/app.css has no top-level @layer utilities block; page styles are disabled for it.')
        ->and(Codes::make(Codes::PAGE_VIEWS_UNANALYSED, ['n' => '2 rendered views have', 'paths' => '/v/a.blade.php, /v/b.blade.php'])->message)->toBe('2 rendered views have no analysis entry (outside bladewind.paths): /v/a.blade.php, /v/b.blade.php. Add their directories to bladewind.paths; until then those pages get the full stylesheet, unless pages.unanalysed is "html", which builds them from their rendered classes alone.')
        ->and(Codes::make(Codes::PAGE_VIEWS_UNANALYSED, ['n' => '2 rendered views have', 'paths' => ''])->severity)->toBe(Severity::Info)
        ->and(Codes::make(Codes::PAGE_STYLES_WRITE_FAILED, ['path' => '/p/bw-page-a.css', 'message' => 'denied'])->message)->toBe('Page stylesheet could not be written to /p/bw-page-a.css: denied.')
        ->and(Codes::make(Codes::PAGE_STYLES_WRITE_FAILED, ['path' => 'x', 'message' => 'y'])->severity)->toBe(Severity::Warning)
        ->and(Codes::make(Codes::STYLESHEET_SUPPORT_UNSHAKEN, ['name' => 'resources/css/app.css', 'reason' => 'nested rule in @layer theme'])->message)->toBe('Stylesheet resources/css/app.css keeps its theme and properties layers whole: nested rule in @layer theme.')
        ->and(Codes::make(Codes::STYLESHEET_SUPPORT_UNSHAKEN, ['name' => 'x', 'reason' => 'y'])->severity)->toBe(Severity::Info);
});

it('ships the pages configuration defaults', function (): void {
    expect(config('bladewind.pages'))->toBe(['enabled' => true, 'max_files' => 500, 'delivery' => 'link', 'unanalysed' => 'fallback', 'keep_variables' => []]);
});
