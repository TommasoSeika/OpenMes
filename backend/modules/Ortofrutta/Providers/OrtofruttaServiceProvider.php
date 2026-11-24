<?php

namespace Modules\Ortofrutta\Providers;

use App\Extension\FilterRegistry;
use App\Services\MenuRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Ortofrutta\Console\DemoCommand;
use Modules\Ortofrutta\Console\VerifyCommand;

/**
 * Wires the module into OpenMES through its extension points only: own
 * migrations, routes and commands, one admin tab and one sidebar group.
 * Registered only while the module is enabled.
 */
class OrtofruttaServiceProvider extends ServiceProvider
{
    public const BASE = '/admin/ortofrutta';

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DemoCommand::class, VerifyCommand::class]);
        }

        // An admin tab of its own: access is the permission `tab:ortofrutta`,
        // which can be granted to a role in Settings → Access.
        app(FilterRegistry::class)->addFilter('tabs.registry', fn ($tabs) => $tabs + [
            'ortofrutta' => ['label' => 'Ortofrutta', 'prefixes' => [self::BASE]],
        ]);

        $this->registerMenu();
    }

    /** The screens, in the order an order travels through them. */
    private function registerMenu(): void
    {
        $menu = app(MenuRegistry::class);
        $menu->addGroup('ortofrutta', 'Ortofrutta', order: 5);

        $items = [
            ['Stabilimento', ''],
            ['Conferimenti', '/conferimenti'],
            ['1 · Ordini del giorno', '/ordini'],
            ['2 · Piano linee', '/piano'],
            ['3 · Squadre', '/squadre'],
            ['4 · Fogli di lavorazione', '/fogli'],
            ['5 · Margini', '/margini'],
            ['Missioni bins', '/missioni'],
        ];
        foreach ($items as $i => [$label, $path]) {
            $menu->addGroupItem('ortofrutta', $label, url(self::BASE.$path), order: ($i + 1) * 10);
        }
    }
}
