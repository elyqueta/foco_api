<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required_without:email', 'string', 'min:2', 'max:60'],
            'email' => [
                'required_without:name',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // Mudar email exige confirmar a palavra-passe actual.
            'currentPassword' => [Rule::requiredIf($this->has('email')), 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without' => 'Envie pelo menos o nome ou o email.',
            'name.min' => 'Mínimo de 2 caracteres.',
            'name.max' => 'Máximo de 60 caracteres.',
            'email.required_without' => 'Envie pelo menos o nome ou o email.',
            'email.email' => 'O email não é válido.',
            'email.unique' => 'Este email já está registado.',
            'currentPassword.required' => 'A palavra-passe atual é obrigatória para mudar o email.',
            'currentPassword.string' => 'A palavra-passe deve ser texto.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user === null || ! $this->filled('currentPassword')) {
                return;
            }

            if (! Hash::check((string) $this->input('currentPassword'), (string) $user->password)) {
                $validator->errors()->add('currentPassword', 'A palavra-passe atual está incorreta.');
            }
        });
    }
}
