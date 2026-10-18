<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\NotificationDispatcher;
use App\Services\NotifyEstimateExceeded;
use App\Services\RecordActivity;
use App\Services\TaskStateMachine;
use App\Services\TimerService;
use Illuminate\Support\Facades\DB;

class CompleteTask
{
    /**
     * Conclui a tarefa (`→ done`, doc 07): marca `completed_at`, fecha um
     * timer aberto (motivo `complete`, com actividade `timer_stopped`) e
     * regista `status_changed`. Se a tarefa é a última por concluir do
     * projeto, devolve a sugestão de concluir o projeto.
     *
     * A conclusão é permitida de `todo`, `in_progress`, `postponed` (concluir
     * antecipadamente) e `expired` (concluída tarde) — nunca de `done`.
     *
     * Notificações (doc 09), disparadas **depois** do commit para não serem
     * desfeitas por um rollback: `task_completed`, `task_estimate_exceeded`
     * (quando o tempo registado atingiu a estimativa) e
     * `project_ready_to_complete` (quando o projeto fica todo concluído).
     *
     * @return array{task: Task, suggestCompleteProject: array{id: string, name: string}|null}
     */
    public function handle(User $user, Task $task): array
    {
        app(TaskStateMachine::class)->assertCan($task->status, TaskStatus::Done);

        $completedAt = now();

        DB::transaction(function () use ($user, $task, $completedAt): void {
            $stoppedEntry = app(CloseOpenTimer::class)->handle($task, 'complete');

            $task->update([
                'status' => TaskStatus::Done,
                'completed_at' => $completedAt,
            ]);

            app(RecordActivity::class)->record(
                $user,
                $task,
                ActivityType::StatusChanged,
                'Estado alterado para '.TaskStatus::Done->label(),
            );

            if ($stoppedEntry instanceof TaskTimeEntry) {
                app(RecordActivity::class)->record(
                    $user,
                    $task,
                    ActivityType::TimerStopped,
                    app(TimerService::class)->stoppedMessage($stoppedEntry),
                );
            }
        });

        $project = $this->projectReadyToComplete($task);

        $dispatcher = app(NotificationDispatcher::class);

        $dispatcher->dispatch(
            $user,
            NotificationType::TaskCompleted,
            $task,
            'completed:'.$completedAt->toIso8601String(),
            ['trackedSeconds' => (int) $task->tracked_seconds],
        );

        app(NotifyEstimateExceeded::class)->handle($user, $task);

        if ($project instanceof Project) {
            $dispatcher->dispatch(
                $user,
                NotificationType::ProjectReadyToComplete,
                $project,
                'ready:'.$completedAt->toIso8601String(),
            );
        }

        $task->setRelation('user', $user);

        return [
            'task' => $task->load('activity'),
            'suggestCompleteProject' => $project instanceof Project
                ? ['id' => $project->id, 'name' => $project->name]
                : null,
        ];
    }

    /**
     * Projeto que fica pronto a concluir com esta tarefa: só quando a
     * tarefa pertence a um projeto e **todas** as tarefas do projeto estão
     * `done`. Uma `expired` (ou qualquer não-`done`) bloqueia (doc 07).
     */
    private function projectReadyToComplete(Task $task): ?Project
    {
        if ($task->project_id === null) {
            return null;
        }

        $project = $task->project()->withCount([
            'tasks',
            'tasks as done_tasks_count' => fn ($query) => $query->where('status', TaskStatus::Done->value),
        ])->first();

        if (! $project instanceof Project || (int) $project->tasks_count === 0) {
            return null;
        }

        if ((int) $project->done_tasks_count !== (int) $project->tasks_count) {
            return null;
        }

        return $project;
    }
}
