<?php

namespace Modules\Ortofrutta\Providers;

use App\Extension\FilterRegistry;
use App\Services\MenuRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Ortofrutta\Console\DemoCommand;
use Modules\Ortofrutta\Console\VerifyCommand;
use Modules\Ortofrutta\Support\Demo;
use Throwable;

/**
 * Wires the module into OpenMES through its extension points only: own
 * migrations, routes and commands, one admin tab and one sidebar group.
 * Registered only while the module is enabled.
 */
class OrtofruttaServiceProvider extends ServiceProvider
{
    public const BASE = '/admin/stabilimento';

    /** Where the screens were before; kept as a redirect. */
    public const OLD_BASE = '/admin/ortofrutta';

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DemoCommand::class, VerifyCommand::class]);
        }

        // An admin tab of its own: access is the permission `tab:ortofrutta`,
        // which can be granted to a role in Settings → Access. Like the menu,
        // it carries the name of the company the plant belongs to.
        app(FilterRegistry::class)->addFilter('tabs.registry', fn ($tabs) => $tabs + [
            'ortofrutta' => ['label' => self::companyName(), 'prefixes' => [self::BASE, self::OLD_BASE]],
        ]);

        $this->registerMenu();
    }

    /**
     * The name the module goes by in the menu: the company's, as set when the
     * demo data is loaded. It lives in the database, not in the code, so the
     * same module can be shown to another company by loading its name.
     */
    public static function companyName(): string
    {
        try {
            return Demo::company();
        } catch (Throwable) {
            // No table yet (module enabled, migrations not run): the default name.
            return Demo::DEFAULT_COMPANY;
        }
    }

    /** The screens, in the order an order travels through them. */
    private function registerMenu(): void
    {
        $menu = app(MenuRegistry::class);
        $menu->addGroup('ortofrutta', self::companyName(), order: 5);

        $items = [
            ['Stabilimento', ''],
            ['Conferimenti', '/conferimenti'],
            ['Ordini del giorno', '/ordini'],
            ['Piano linee', '/piano'],
            ['Squadre', '/squadre'],
            ['Liste di lavoro', '/missioni'],
            ['Terminale', '/terminale'],
            ['Fogli di lavorazione', '/fogli'],
            ['Tracciabilità', '/tracciabilita'],
            ['Margini', '/margini'],
        ];
        foreach ($items as $i => [$label, $path]) {
            $menu->addGroupItem('ortofrutta', $label, url(self::BASE.$path), order: ($i + 1) * 10);
        }
    }
}
