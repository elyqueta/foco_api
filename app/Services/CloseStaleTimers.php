<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;

/**
 * Higiene de timers esquecidos (doc 08 regra 11): uma entrada aberta há mais
 * de `HOURS` horas é fechada automaticamente (motivo `auto_pause`) com a
 * actividade "Timer pausado automaticamente (inatividade)". Evita somar
 * noites inteiras por esquecimento. Corre no `foco:check-timers` (tick) e
 * opcionalmente para um único utilizador.
 *
 * Nunca pausa timers recentes: um timer a correr há 16 h ou mais é, com
 * probabilidade esmagadora, um esquecimento — mas o acumulado fica em
 * `tracked_seconds` através do fecho normal.
 */
class CloseStaleTimers
{
    public const HOURS = 16;

    public function handle(?int $userId = null): int
    {
        $threshold = now()->subHours(self::HOURS);

        $query = TaskTimeEntry::query()
            ->open()
            ->where('started_at', '<=', $threshold)
            ->with('task.user.notificationPreference');

        if ($userId !== null) {
            $query->forUser($userId);
        }

        $closed = 0;

        foreach ($query->cursor() as $entry) {
            $task = $entry->task;

            if (! $task instanceof Task || ! $task->user instanceof User) {
                continue;
            }

            $stopped = app(CloseOpenTimer::class)->handle($task, 'auto_pause');

            if (! $stopped instanceof TaskTimeEntry) {
                continue;
            }

            app(RecordActivity::class)->record(
                $task->user,
                $task,
                ActivityType::TimerPaused,
                'Timer pausado automaticamente (inatividade)',
            );

            $closed++;
        }

        return $closed;
    }
}
