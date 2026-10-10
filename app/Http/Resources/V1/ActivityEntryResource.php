<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsContractDates;
use App\Models\ActivityEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ActivityEntry $resource
 */
class ActivityEntryResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'at' => $this->contractDate($this->resource->at),
            'type' => $this->resource->type->value,
            'message' => $this->resource->message,
        ];
    }
}
