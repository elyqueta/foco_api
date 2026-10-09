<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Settings\UpdateSettingsRequest;
use App\Http\Resources\V1\SettingsResource;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return SettingsResource::make($user)->response();
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data): void {
            $this->updateUserFields($user, $data);

            if (array_key_exists('notifications', $data)) {
                $this->updateNotifications($user, $data['notifications']);
            }
        });

        return SettingsResource::make($user)->response();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateUserFields(User $user, array $data): void
    {
        $fields = [];

        foreach (['userName' => 'name', 'theme' => 'theme', 'timezone' => 'timezone'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        if ($fields !== []) {
            $user->update($fields);
        }
    }

    /**
     * @param  array<string, mixed>  $notifications
     */
    private function updateNotifications(User $user, array $notifications): void
    {
        $preference = $user->notificationPreference()->firstOrCreate(
            ['user_id' => $user->id],
            NotificationPreference::defaultAttributes(),
        );

        $columns = [
            'emailEnabled' => 'email_enabled',
            'inAppEnabled' => 'in_app_enabled',
            'digestHour' => 'digest_hour',
            'dueSoonHours' => 'due_soon_hours',
            'dueImminentMinutes' => 'due_imminent_minutes',
            'timerLongHours' => 'timer_long_hours',
        ];

        $fields = [];

        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $notifications)) {
                $fields[$column] = $notifications[$key];
            }
        }

        if (array_key_exists('types', $notifications) && is_array($notifications['types'])) {
            $fields['types'] = array_replace_recursive(
                $preference->types ?? [],
                $notifications['types'],
            );
        }

        if ($fields !== []) {
            $preference->update($fields);
        }

        $user->setRelation('notificationPreference', $preference);
    }
}
