<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CloseStaleTimers;
use App\Services\NotifyLongTimers;
use Illuminate\Console\Command;

/**
 * `foco:check-timers` (doc 08 tarefa 8.5): usado pelo tick do scheduler
 * (Fase 10) e corrido à mão quando necessário.
 *
 *  1. **Higiene** — fecha entradas abertas há mais de 16 h
 *     (`auto_pause`, actividade "Timer pausado automaticamente
 *     (inatividade)").
 *  2. **Timers longos** — notifica (in-app, uma vez por entrada) os timers
 *     abertos há `timer_long_hours` ou mais.
 *
 * `--user=` limita a um utilizador (útil para depurar).
 */
class FocoCheckTimers extends Command
{
    protected $signature = 'foco:check-timers
        {--user= : ID do utilizador (por omissão, todos)}';

    protected $description = 'Verifica os timers: higiene dos esquecidos (> 16 h) e notificação dos que estão a correr há muito tempo';

    public function handle(CloseStaleTimers $closeStaleTimers, NotifyLongTimers $notifyLongTimers): int
    {
        $user = $this->option('user');

        // `--user` é opcional, mas quando é dado tem de ser um ID: um valor
        // inválido não pode significar silenciosamente "todos os
        // utilizadores" — este comando mexe em dados.
        if ($user !== null && (! is_numeric($user) || (int) $user < 1)) {
            $this->error('O --user tem de ser o ID (inteiro positivo) de um utilizador.');

            return self::FAILURE;
        }

        $userId = is_numeric($user) ? (int) $user : null;

        $closed = $closeStaleTimers->handle($userId);
        $notified = $notifyLongTimers->handle($userId);

        $this->info("Timers fechados por inatividade (> 16 h): {$closed}");
        $this->info("Notificações de timer longo criadas: {$notified}");

        return self::SUCCESS;
    }
}
