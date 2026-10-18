<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Onboarding;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcar onboarding como concluído (`POST /v1/onboarding/complete`).
 *
 * Aceita opcionalmente `currentStep` para avançar o passo atual.
 */
class CompleteOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'currentStep' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currentStep.integer' => 'O passo atual deve ser um número inteiro.',
            'currentStep.min' => 'O passo atual deve ser pelo menos 1.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currentStep') && is_string($this->input('currentStep'))) {
            $this->merge(['currentStep' => trim($this->input('currentStep'))]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $currentStep = $this->input('currentStep');

            if ($currentStep !== null && $currentStep !== '') {
                $user = $this->user();
                if ($user && (int) $currentStep < (int) $user->onboarding_current_step) {
                    $validator->errors()->add(
                        'currentStep',
                        'O passo atual não pode ser menor que o passo já registado.',
                    );
                }
            }
        });
    }
}
