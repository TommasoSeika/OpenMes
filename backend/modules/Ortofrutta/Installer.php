<?php

namespace Modules\Ortofrutta;

use Modules\Ortofrutta\Services\DemoLoader;
use Modules\Ortofrutta\Support\Demo;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Runs when the module is enabled (Admin → Modules, or bin/attiva-demo.sh),
 * after its migrations: creates the tab permission and loads the simulated
 * data, so the screens are not empty the first time they are opened.
 */
class Installer
{
    public function install(): void
    {
        try {
            Permission::firstOrCreate(['name' => 'tab:ortofrutta', 'guard_name' => 'web']);
        } catch (Throwable $e) {
            // The roles seeder creates it on the next start anyway.
            report($e);
        }

        if (! Demo::loaded()) {
            app(DemoLoader::class)->load();
        }
    }

    /** The tables stay: disabling the module must not throw the data away. */
    public function uninstall(): void {}
}
