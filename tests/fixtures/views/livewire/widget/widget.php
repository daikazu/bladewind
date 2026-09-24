<?php

declare(strict_types=1);

/**
 * The class half of a Livewire multi-file component. Never autoloaded; it exists so
 * LivewireSfcDetector sees the sibling file Livewire's Finder::hasValidMultiFileComponentSource()
 * requires next to widget.blade.php.
 */
class Widget
{
    public int $count = 0;
}
