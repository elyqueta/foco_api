<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\FormatsContractDates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;

/**
 * Notificação in-app (contrato):
 * `{ id, type, title, body, data: { taskId?, projectId? }, readAt, createdAt }`.
 *
 * `type` é o valor do `NotificationType` (ex. `task_completed`) e não a
 * classe da notificação — ver `FocoNotification::databaseType()`.
 *
 * @property-read DatabaseNotification $resource
 */
class NotificationResource extends JsonResource
{
    use FormatsContractDates;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->data;

        /** @var array<string, mixed>|null $data */
        $data = is_array($data) ? $data : null;

        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'body' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'data' => Arr::only($data ?? [], ['taskId', 'projectId']),
            'readAt' => $this->contractDate($this->resource->read_at),
            'createdAt' => $this->contractDate($this->resource->created_at),
        ];
    }
}
