<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Pages;

use Daikazu\BladeWind\BladeWind;
use Daikazu\BladeWind\Http\ServeAsset;

/**
 * Keeps the page assets in the head correct across `wire:navigate`.
 *
 * Livewire's `mergeNewHead()` appends a stylesheet it has not seen and keeps every one it has, so
 * without the per-response marker an earlier page's asset keeps its old place, and later pages'
 * copies of shared base rules (`.btn`, `p-4`) override the current page's (`.btn-primary`, `px-2`).
 * The marker makes each navigation append the current asset last; this script removes the stale
 * ones so a long session does not accumulate sheets. It runs once (the window flag guards a repeat)
 * and is inert without Livewire.
 *
 * Delivered inline with the nonce when the application registered one with Vite, otherwise as a
 * file served by {@see ServeAsset}, so a `script-src 'self'` policy still runs it.
 */
final class NavigateScript
{
    public const SOURCE = "window.bladewindNavigate||(window.bladewindNavigate=1,document.addEventListener('livewire:navigated',function(){var n=document.querySelectorAll('head [data-bladewind-nav]');for(var i=0;i<n.length-1;i++)n[i].remove()}))";

    /**
     * The file name the script is served by, content-addressed over the source and the package
     * version, so a browser that cached one release's script fetches the next release's.
     */
    public static function file(): string
    {
        return 'bw-navigate-'.substr(hash('xxh128', self::SOURCE."\n".BladeWind::VERSION), 0, 12).'.js';
    }
}
