<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Category $resource
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'isDefault' => (bool) $this->resource->is_default,
            'tasksCount' => (int) ($this->resource->tasks_count ?? 0),
            'projectsCount' => (int) ($this->resource->projects_count ?? 0),
        ];
    }
}
