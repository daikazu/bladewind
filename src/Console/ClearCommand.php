<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Console;

use Daikazu\BladeWind\Manifest\ManifestStore;
use Daikazu\BladeWind\Pages\PageStyleStore;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'bladewind:clear';

    protected $description = 'Remove BladeWind manifest entries, the aggregate report, and the generated page stylesheets.';

    public function handle(ManifestStore $store, PageStyleStore $pages): int
    {
        $root = $store->root();

        if ($store->clear()) {
            $this->components->info("Removed {$root}");
        } else {
            $this->components->info("Nothing to clear at {$root}");
        }

        $removedPages = $pages->clear();
        $this->components->info("Removed {$removedPages} page stylesheets");

        // A rendered view whose entry is missing is analysed on the spot, so nothing else needs
        // clearing; the command exists for a whole-tree pass.
        $this->line('Entries come back as views render, or all at once with bladewind:analyze.');

        return self::SUCCESS;
    }
}
