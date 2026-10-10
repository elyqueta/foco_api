<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\CloseOpenTimer;
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
     * @return array{task: Task, suggestCompleteProject: array{id: string, name: string}|null}
     */
    public function handle(User $user, Task $task): array
    {
        app(TaskStateMachine::class)->assertCan($task->status, TaskStatus::Done);

        $suggest = null;

        DB::transaction(function () use ($user, $task, &$suggest): void {
            $stoppedEntry = app(CloseOpenTimer::class)->handle($task, 'complete');

            $task->update([
                'status' => TaskStatus::Done,
                'completed_at' => now(),
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

        $suggest = $this->suggestCompleteProject($task);

        $task->setRelation('user', $user);

        return ['task' => $task->load('activity'), 'suggestCompleteProject' => $suggest];
    }

    /**
     * Sugestão de concluir o projeto: só quando a tarefa pertence a um
     * projeto e **todas** as tarefas do projeto estão `done`. Uma `expired`
     * (ou qualquer não-`done`) bloqueia a sugestão (doc 07).
     *
     * @return array{id: string, name: string}|null
     */
    private function suggestCompleteProject(Task $task): ?array
    {
        if ($task->project_id === null) {
            return null;
        }

        $project = $task->project()->withCount([
            'tasks',
            'tasks as done_tasks_count' => fn ($query) => $query->where('status', TaskStatus::Done->value),
        ])->first();

        if (! $project || (int) $project->tasks_count === 0) {
            return null;
        }

        if ((int) $project->done_tasks_count !== (int) $project->tasks_count) {
            return null;
        }

        return ['id' => $project->id, 'name' => $project->name];
    }
}
