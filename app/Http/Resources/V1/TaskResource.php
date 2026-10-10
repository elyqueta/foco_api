<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Actions\Tasks\DueDate;
use App\Enums\TimerState;
use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato do `Task` (contrato-api.md). A chave `activity` só aparece no
 * detalhe (GET/tasks/{id}, respostas de ações) — as listas não carregam a
 * relação, por isso o campo é omitido (T-08).
 *
 * O bloco `timer` existe desde já com `state=idle`: o timer propriamente dito
 * é implementado na Fase 8. `serverNow` vem sempre em UTC para o front poder
 * compensar a deriva do relógio.
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
     * Bloco `timer` do contrato. Na Fase 7 o timer ainda não corre: estado
     * `idle`, `runningSince` nulo e `trackedSeconds` lido da coluna (que a
     * Fase 8 passa a gerir). `serverNow` é sempre o instante actual em UTC.
     *
     * @return array<string, mixed>
     */
    private function timer(): array
    {
        return [
            'state' => TimerState::Idle->value,
            'runningSince' => null,
            'trackedSeconds' => (int) $this->resource->tracked_seconds,
            'serverNow' => $this->contractDate(now()),
            'firstStartedAt' => $this->contractDate($this->resource->first_started_at),
        ];
    }
}
