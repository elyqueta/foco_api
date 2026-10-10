<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\TaskTimeEntry;
use App\Services\TimerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato do `TimeEntry` (contrato-api.md): `{ id, startedAt, endedAt,
 * endedReason, seconds }`. `endedReason` é `pause|auto_pause|complete|
 * postpone|reset|null`; `seconds` de uma entrada aberta conta até agora
 * (hora do servidor).
 *
 * @property-read TaskTimeEntry $resource
 */
class TimeEntryResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'startedAt' => $this->contractDate($this->resource->started_at),
            'endedAt' => $this->contractDate($this->resource->ended_at),
            'endedReason' => $this->resource->ended_reason,
            'seconds' => app(TimerService::class)->secondsOf($this->resource),
        ];
    }
}
