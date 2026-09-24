<?php

declare(strict_types=1);

namespace Daikazu\BladeWind\Integration;

use Composer\InstalledVersions as Composer;

class InstalledVersions
{
    public function version(string $package): ?string
    {
        if (! Composer::isInstalled($package)) {
            return null;
        }

        return Composer::getPrettyVersion($package);
    }
}
