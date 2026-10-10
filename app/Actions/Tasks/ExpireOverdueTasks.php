<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\User;
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
     * Notificação `task_expired` (dedupe por tarefa) é criada na Fase 9/10.
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
            DB::transaction(function () use ($user, $task, &$expired): void {
                // Update "guardado": só escreve se a tarefa ainda está aberta.
                // Sob concorrência (dois GET/tasks, ou GET a sobrepor-se ao
                // tick/scheduler) só um escritor inverte o estado; o outro vê
                // 0 linhas e não duplica a actividade `expired` nem `expired_at`.
                $flipped = $task->newQuery()
                    ->whereKey($task->id)
                    ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value])
                    ->update([
                        'status' => TaskStatus::Expired,
                        'expired_at' => now(),
                    ]);

                if ($flipped === 0) {
                    return;
                }

                $expired++;

                app(RecordActivity::class)->record(
                    $user,
                    $task,
                    ActivityType::Expired,
                    'Tarefa expirada por prazo vencido',
                );
            });
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
