<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Discovery;

enum ViewKind: string
{
    case View = 'view';
    case Component = 'component';

    /**
     * A Blade file Livewire generated under `config('view.compiled')/livewire/views` from a
     * single-file or multi-file component. It is the file the component actually renders, so it
     * is the path a view composer sees and the only key a page's class set can look it up by.
     */
    case LivewireCompiled = 'livewire-compiled';
}
