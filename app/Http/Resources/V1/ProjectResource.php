<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato do `Project` (contrato-api.md). A chave `activity` só aparece
 * no detalhe (GET /projects/{id}, respostas de criação/atualização) —
 * as listas não carregam a relação, por isso o campo é omitido.
 *
 * @property-read Project $resource
 */
class ProjectResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'category' => $this->resource->category,
            'urgency' => $this->resource->urgency->value,
            'status' => $this->resource->status->value,
            'canPostpone' => (bool) $this->resource->can_postpone,
            'dueDate' => $this->resource->due_date?->format('Y-m-d'),
            'nextStep' => $this->resource->next_step,
            'color' => $this->resource->color,
            'progress' => $this->resource->progress,
            'createdAt' => $this->contractDate($this->resource->created_at),
            'updatedAt' => $this->contractDate($this->resource->updated_at),
        ];

        if ($this->resource->relationLoaded('activity')) {
            $data['activity'] = ActivityEntryResource::collection($this->resource->activity);
        }

        return $data;
    }
}
