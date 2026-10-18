<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Settings\UpdateSettingsRequest;
use App\Http\Resources\V1\SettingsResource;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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

        $userFields = $this->userFields($user, $data);

        $notificationFields = array_key_exists('notifications', $data)
            ? $this->notificationFields($user, $data['notifications'])
            : [];

        // Padrão da API: se nada muda, não atualiza nem responde com sucesso.
        if ($userFields === [] && $notificationFields === []) {
            throw DomainRuleException::noChanges();
        }

        DB::transaction(function () use ($user, $userFields, $notificationFields): void {
            if ($userFields !== []) {
                $user->update($userFields);
            }

            if ($notificationFields !== []) {
                $preference = $user->notificationPreference()->firstOrCreate(
                    ['user_id' => $user->id],
                    NotificationPreference::defaultAttributes(),
                );

                $preference->update($notificationFields);

                $user->setRelation('notificationPreference', $preference);
            }
        });

        return SettingsResource::make($user)->response();
    }

    /**
     * Campos do utilizador que realmente mudam (diff contra o valor atual).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function userFields(User $user, array $data): array
    {
        $fields = [];

        foreach (['userName' => 'name', 'theme' => 'theme', 'timezone' => 'timezone'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[$column] = $data[$key];
            }
        }

        return $this->onlyChanged($user, $fields);
    }

    /**
     * Campos das preferências de notificação que realmente mudam.
     *
     * @param  array<string, mixed>  $notifications
     * @return array<string, mixed>
     */
    private function notificationFields(User $user, array $notifications): array
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

        return $this->onlyChanged($preference, $fields);
    }

    /**
     * Filtra apenas os atributos cujo valor difere do actual (comparação
     * flexível para ignorar ruído de tipo, ex. 8 vs "8").
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function onlyChanged(Model $model, array $fields): array
    {
        return array_filter(
            $fields,
            fn (mixed $value, string $column): bool => $model->getAttribute($column) != $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
