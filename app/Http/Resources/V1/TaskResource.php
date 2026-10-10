<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Actions\Tasks\DueDate;
use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\Task;
use App\Services\TimerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato do `Task` (contrato-api.md). A chave `activity` só aparece no
 * detalhe (GET/tasks/{id}, respostas de ações) — as listas não carregam a
 * relação, por isso o campo é omitido (T-08).
 *
 * O bloco `timer` é calculado a partir de `task_time_entries` +
 * `tasks.tracked_seconds`: `trackedSeconds` já **inclui** o tempo da entrada
 * a decorrer, para o cliente só somar o que passa desde a resposta.
 * `serverNow` vem sempre em UTC para o front compensar a deriva do relógio.
 *
 * @property-read Task $resource
 */
class TaskResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->resource->id,
            'projectId' => $this->resource->project_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'category' => $this->resource->category,
            'urgency' => $this->resource->urgency->value,
            'status' => $this->resource->status->value,
            'canPostpone' => (bool) $this->resource->can_postpone,
            'dueDate' => DueDate::format($this->resource->due_date, $this->resource->due_time),
            'nextStep' => $this->resource->next_step,
            'estimateMinutes' => $this->resource->estimate_minutes,
            'tags' => $this->resource->tags ?? [],
            'isOverdue' => $this->resource->isOverdue(),
            'postponedCount' => (int) $this->resource->postponed_count,
            'timer' => $this->timer(),
            'createdAt' => $this->contractDate($this->resource->created_at),
            'updatedAt' => $this->contractDate($this->resource->updated_at),
            'completedAt' => $this->contractDate($this->resource->completed_at),
        ];

        if ($this->resource->relationLoaded('activity')) {
            $data['activity'] = ActivityEntryResource::collection($this->resource->activity);
        }

        return $data;
    }

    /**
     * Bloco `timer` do contrato (doc 08). A entrada aberta é lida pela
     * relação `activeTimeEntry`: nas listas ela vem carregada com
     * `with('activeTimeEntry')` (uma query extra para toda a lista) e no
     * detalhe é uma query única — nunca N+1.
     *
     * @return array<string, mixed>
     */
    private function timer(): array
    {
        $timers = app(TimerService::class);

        $task = $this->resource;

        $open = $timers->open($task);

        return [
            'state' => $timers->state($task)->value,
            'runningSince' => $this->contractDate($open?->started_at),
            'trackedSeconds' => $timers->trackedSeconds($task),
            'serverNow' => $this->contractDate(now()),
            'firstStartedAt' => $this->contractDate($task->first_started_at),
        ];
    }
}
