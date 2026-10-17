<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TickService;
use Illuminate\Console\Command;

/**
 * `foco:tick` (doc 10): corre o tick do scheduler à mão (dev/testes). Em
 * dev local usa-se com `php artisan schedule:work`, que chama o comando de
 * 5 em 5 minutos; em produção o mesmo tick é chamado por um agendador
 * externo em `POST /api/internal/scheduler/tick` (com `X-Cron-Secret`), sem
 * worker nem Cron Job pagos.
 */
class FocoTick extends Command
{
    protected $signature = 'foco:tick';

    protected $description = 'Corre o tick do scheduler: expira tarefas, verifica timers, envia lembretes de prazo e digest diário';

    public function handle(TickService $ticks): int
    {
        $result = $ticks->run();

        if (($result['skipped'] ?? null) === TickService::SKIPPED_LOCKED) {
            $this->warn('Já existe um tick a correr — nada foi feito.');

            return self::SUCCESS;
        }

        $this->info('Foco tick em '.$result['durationMs'].' ms');
        $this->info('Tarefas expiradas: '.$result['expired']);
        $this->info('In-app enviadas: '.$result['sent']['in_app']);
        $this->info('Emails enviados: '.$result['sent']['email']);
        $this->info('Ignorados (teto diário/agregados): '.$result['skipped']);
        $this->info('Falhados: '.$result['failed']);

        if ($result['timeBudgetExceeded']) {
            $this->warn('Orçamento de tempo do tick esgotado — o resto corre no próximo tick.');
        }

        return self::SUCCESS;
    }
}
