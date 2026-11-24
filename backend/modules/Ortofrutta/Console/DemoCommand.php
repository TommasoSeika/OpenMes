<?php

namespace Modules\Ortofrutta\Console;

use Illuminate\Console\Command;
use Modules\Ortofrutta\Services\DemoLoader;
use Modules\Ortofrutta\Support\Demo;

class DemoCommand extends Command
{
    protected $signature = 'ortofrutta:demo {--azienda= : Company name shown in the header of every screen}';

    protected $description = 'Reload the simulated data of the Ortofrutta module from the CSV files in seed/';

    public function handle(DemoLoader $loader): int
    {
        $counts = $loader->load($this->option('azienda'));

        $this->info('Dati demo caricati per "'.Demo::company().'".');
        $this->table(['Cosa', 'Quanti'], [
            ['Linee', $counts['lines']], ['Celle frigo', $counts['cells']], ['Produttori', $counts['producers']],
            ['Lotti', $counts['lots']], ['Articoli', $counts['items']], ['Persone', $counts['people']],
            ['Ordini', $counts['orders']], ['Fogli di lavorazione', $counts['sheets']],
        ]);
        $this->line('Giornata demo: '.Demo::day()->toDateString().', ore '.Demo::clock().'.');

        return self::SUCCESS;
    }
}
