<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * `foco:prune-notifications` (doc 09 tarefa 9.6): retenção das notificações
 * in-app. Apaga as notificações **lidas** com mais de `READ_DAYS` dias e
 * **quaisquer** notificações com mais de `ANY_DAYS` dias (lidas ou não) —
 * a tabela não pode crescer para sempre.
 *
 * Corre uma vez por dia (agendado em `routes/console.php` e, mais tarde,
 * chamado pelo tick da Fase 10).
 */
class FocoPruneNotifications extends Command
{
    /**
     * Notificações lidas: apagadas ao fim deste tempo.
     */
    public const READ_DAYS = 30;

    /**
     * Notificações não lidas: apagadas ao fim deste tempo (tecto duro).
     */
    public const ANY_DAYS = 90;

    protected $signature = 'foco:prune-notifications';

    protected $description = 'Remove notificações in-app antigas (lidas > 30 dias; todas > 90 dias)';

    public function handle(): int
    {
        $readRemoved = DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(self::READ_DAYS))
            ->delete();

        $anyRemoved = DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays(self::ANY_DAYS))
            ->delete();

        $this->info('Notificações lidas removidas (> '.self::READ_DAYS.' dias): '.$readRemoved);
        $this->info('Notificações antigas removidas (> '.self::ANY_DAYS.' dias): '.$anyRemoved);

        return self::SUCCESS;
    }
}
