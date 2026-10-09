<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $defaults = NotificationPreference::defaultAttributes();
        $preference = $this->resource->notificationPreference;

        return [
            'userName' => $this->resource->name,
            'theme' => $this->resource->theme,
            'timezone' => $this->resource->timezone,
            'email' => $this->resource->email,
            'notifications' => [
                'emailEnabled' => (bool) ($preference?->email_enabled ?? $defaults['email_enabled']),
                'inAppEnabled' => (bool) ($preference?->in_app_enabled ?? $defaults['in_app_enabled']),
                'digestHour' => (int) ($preference?->digest_hour ?? $defaults['digest_hour']),
                'dueSoonHours' => (int) ($preference?->due_soon_hours ?? $defaults['due_soon_hours']),
                'dueImminentMinutes' => (int) ($preference?->due_imminent_minutes ?? $defaults['due_imminent_minutes']),
                'timerLongHours' => (int) ($preference?->timer_long_hours ?? $defaults['timer_long_hours']),
                'types' => $preference !== null
                    ? $preference->typesWithDefaults()
                    : NotificationPreference::defaultTypes(),
            ],
        ];
    }
}
