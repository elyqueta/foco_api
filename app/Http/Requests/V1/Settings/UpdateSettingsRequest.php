<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Settings;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'userName' => ['sometimes', 'string', 'min:2', 'max:60'],
            'theme' => ['sometimes', 'string', 'in:light,dark'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'notifications' => ['sometimes', 'array'],
            'notifications.emailEnabled' => ['sometimes', 'boolean'],
            'notifications.inAppEnabled' => ['sometimes', 'boolean'],
            'notifications.digestHour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'notifications.dueSoonHours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'notifications.dueImminentMinutes' => ['sometimes', 'integer', 'min:5', 'max:720'],
            'notifications.timerLongHours' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'notifications.types' => ['sometimes', 'array'],
            'notifications.types.*' => ['sometimes', 'array'],
            'notifications.types.*.email' => ['sometimes', 'boolean'],
            'notifications.types.*.inApp' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $types = $this->input('notifications.types');

            if (! is_array($types)) {
                return;
            }

            $validTypes = NotificationType::values();

            foreach (array_keys($types) as $type) {
                if (! in_array($type, $validTypes, true)) {
                    $validator->errors()->add(
                        'notifications.types.'.$type,
                        'Tipo de notificação desconhecido.',
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['userName', 'theme', 'timezone'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * Mantém apenas os canais previstos no contrato (email/inApp): chaves
     * desconhecidas não são validadas, nem guardadas, nem devolvidas.
     */
    protected function passedValidation(): void
    {
        $notifications = $this->input('notifications');

        if (! is_array($notifications) || ! is_array($notifications['types'] ?? null)) {
            return;
        }

        $types = [];

        foreach ($notifications['types'] as $type => $channels) {
            if (is_array($channels)) {
                $types[$type] = Arr::only($channels, ['email', 'inApp']);
            }
        }

        $notifications['types'] = $types;

        $this->merge(['notifications' => $notifications]);
    }
}
