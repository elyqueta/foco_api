<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\User;
use App\Services\TimerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exportação de dados do utilizador (doc 11 §"Exportação").
 * Formato `AppData` compatível com `foco-backup.json`:
 * `{ schemaVersion, projects, tasks, settings, categories }`.
 *
 * Inclui `timer.trackedSeconds` por tarefa e atividade de cada projeto/tarefa.
 *
 * @property-read User $resource
 */
class AppDataResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $projects = $user->projects()
            ->with('activity')
            ->orderBy('created_at')
            ->get();

        $tasks = $user->tasks()
            ->with('activity', 'activeTimeEntry')
            ->orderBy('created_at')
            ->get();

        $timers = app(TimerService::class);

        return [
            'schemaVersion' => 1,
            'projects' => $projects->map(fn ($project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'category' => $project->category,
                'urgency' => $project->urgency?->value,
                'status' => $project->status->value,
                'canPostpone' => (bool) $project->can_postpone,
                'dueDate' => $project->due_date?->format('Y-m-d'),
                'nextStep' => $project->next_step,
                'color' => $project->color,
                'activity' => $project->relationLoaded('activity')
                    ? ActivityEntryResource::collection($project->activity)->resolve()
                    : [],
            ])->values()->all(),
            'tasks' => $tasks->map(fn ($task): array => [
                'id' => $task->id,
                'projectId' => $task->project_id,
                'title' => $task->title,
                'description' => $task->description,
                'category' => $task->category,
                'urgency' => $task->urgency?->value,
                'status' => $task->status->value,
                'canPostpone' => (bool) $task->can_postpone,
                'dueDate' => $task->due_date?->format('Y-m-d'),
                'dueTime' => $task->due_time,
                'nextStep' => $task->next_step,
                'estimateMinutes' => $task->estimate_minutes,
                'tags' => $task->tags ?? [],
                'trackedSeconds' => $timers->trackedSeconds($task),
                'activity' => $task->relationLoaded('activity')
                    ? ActivityEntryResource::collection($task->activity)->resolve()
                    : [],
            ])->values()->all(),
            'settings' => [
                'theme' => $user->theme,
                'userName' => $user->name,
            ],
            'categories' => $user->categories()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        ];
    }
}
