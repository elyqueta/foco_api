<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\RecordActivity;
use App\Services\TaskStateMachine;
use Illuminate\Support\Facades\DB;

class ReopenTask
{
    /**
     * Reabre a tarefa (`done`/`expired` → `todo`, doc 07). Limpa
     * `completed_at`, fecha um timer aberto (motivo `pause`) e regista
     * `status_changed`.
     *
     * Restringido a `done`/`expired`: reabrir uma tarefa já aberta
     * (`todo`/`in_progress`/`postponed`) não tem sentido — essas usam o
     * `PATCH status` (doc 07). Uma `expired` reaberta volta a aparecer como
     * atrasada e será reexpirada na verificação preguiçosa/tick; para a
     * reactivar de facto é preciso enviar um novo `dueDate ≥ hoje`.
     */
    public function handle(User $user, Task $task): Task
    {
        if (! in_array($task->status, [TaskStatus::Done, TaskStatus::Expired], true)) {
            throw new DomainRuleException(
                'INVALID_TRANSITION',
                "Transição de estado inválida: {$task->status->value} → ".TaskStatus::Todo->value.'.',
                422,
            );
        }

        app(TaskStateMachine::class)->assertCan($task->status, TaskStatus::Todo);

        DB::transaction(function () use ($user, $task): void {
            app(CloseOpenTimer::class)->handle($task, 'pause');

            $task->update([
                'status' => TaskStatus::Todo,
                'completed_at' => null,
            ]);

            app(RecordActivity::class)->record(
                $user,
                $task,
                ActivityType::StatusChanged,
                'Estado alterado para '.TaskStatus::Todo->label(),
            );
        });

        $task->setRelation('user', $user);

        return $task->load('activity');
    }
}
