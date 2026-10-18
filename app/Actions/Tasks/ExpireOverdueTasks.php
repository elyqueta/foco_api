<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\RecordActivity;
use App\Services\UserClock;
use Illuminate\Support\Facades\DB;

class ExpireOverdueTasks
{
    /**
     * Expira, para um utilizador, as tarefas com prazo vencido (doc 07,
     * §7.2 / regras §5.2): `status IN (todo, in_progress)` com `due_date` <
     * hoje (fuso do utilizador) e **sem** entrada de tempo aberta (uma
     * tarefa com timer a correr não expira até ser pausada/concluída).
     *
     * Idempotente: depois de expirada a tarefa deixa de corresponder à
     * query. Para cada uma, numa transação própria: `status=expired`,
     * `expired_at=now` e actividade `expired`.
     *
     * Notificação `task_expired` (doc 09), uma por tarefa e dia de
     * expiração (dedupe `expired:{data}`), disparada **depois** do commit
     * de cada tarefa — falhar no envio nunca desfaz a expiração.
     */
    public function forUser(User $user): int
    {
        $today = app(UserClock::class)->today($user)->format('Y-m-d');

        $tasks = $user->tasks()
            ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->whereDoesntHave('activeTimeEntry')
            ->get();

        $expired = 0;

        foreach ($tasks as $task) {
            $expiredAt = now();

            $flipped = DB::transaction(function () use ($user, $task, $expiredAt): bool {
                // Update "guardado": só escreve se a tarefa ainda está aberta.
                // Sob concorrência (dois GET/tasks, ou GET a sobrepor-se ao
                // tick/scheduler) só um escritor inverte o estado; o outro vê
                // 0 linhas e não duplica a actividade `expired` nem `expired_at`.
                $flipped = $task->newQuery()
                    ->whereKey($task->id)
                    ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
                    ->update([
                        'status' => TaskStatus::Expired,
                        'expired_at' => $expiredAt,
                    ]);

                if ($flipped === 0) {
                    return false;
                }

                app(RecordActivity::class)->record(
                    $user,
                    $task,
                    ActivityType::Expired,
                    'Tarefa expirada por prazo vencido',
                );

                return true;
            });

            if (! $flipped) {
                continue;
            }

            $expired++;

            app(NotificationDispatcher::class)->dispatch(
                $user,
                NotificationType::TaskExpired,
                $task,
                'expired:'.$expiredAt->format('Y-m-d'),
                ['referenceAt' => $task->due_date ?? $expiredAt],
            );
        }

        return $expired;
    }

    /**
     * Versão de todos os utilizadores, usada pelo comando
     * `foco:expire-overdue` e (mais tarde) pelo tick do scheduler.
     */
    public function forAll(): int
    {
        $total = 0;

        foreach (User::query()->cursor() as $user) {
            $total += $this->forUser($user);
        }

        return $total;
    }
}
