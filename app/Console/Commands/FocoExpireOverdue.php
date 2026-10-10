<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\ExpireOverdueTasks;
use Illuminate\Console\Command;

class FocoExpireOverdue extends Command
{
    protected $signature = 'foco:expire-overdue';

    protected $description = 'Expira as tarefas com prazo vencido de todos os utilizadores (doc 07 §7.2).';

    public function handle(): int
    {
        $count = app(ExpireOverdueTasks::class)->forAll();

        $this->info("{$count} tarefa(s) expirada(s) por prazo vencido.");

        return Command::SUCCESS;
    }
}
