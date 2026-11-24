<?php

namespace Modules\Ortofrutta\Console;

use Illuminate\Console\Command;
use Modules\Ortofrutta\Domain\SelfCheck;

class VerifyCommand extends Command
{
    protected $signature = 'ortofrutta:verifica';

    protected $description = 'Check the calculation rules of the Ortofrutta module (no database, no PHPUnit needed)';

    public function handle(): int
    {
        $failed = 0;
        foreach (SelfCheck::run() as $check) {
            if ($check['ok']) {
                $this->line('  <info>OK</info>   '.$check['label']);
            } else {
                $failed++;
                $this->line('  <error>NO</error>   '.$check['label'].' — atteso '.$check['expected'].', ottenuto '.$check['actual']);
            }
        }

        $this->newLine();
        if ($failed > 0) {
            $this->error($failed.' regole non rispettate.');

            return self::FAILURE;
        }
        $this->info('Tutte le regole di calcolo sono rispettate.');

        return self::SUCCESS;
    }
}
