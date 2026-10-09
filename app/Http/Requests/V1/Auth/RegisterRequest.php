<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'passwordConfirmation' => ['required', 'string', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.min' => 'Mínimo de 2 caracteres.',
            'name.max' => 'Máximo de 60 caracteres.',
            'email.required' => 'O email é obrigatório.',
            'email.email' => 'O email não é válido.',
            'email.unique' => 'Este email já está registado.',
            'password.required' => 'A palavra-passe é obrigatória.',
            'password.min' => 'Mínimo de 8 caracteres.',
            'passwordConfirmation.required' => 'A confirmação da palavra-passe é obrigatória.',
            'passwordConfirmation.same' => 'As palavras-passe não coincidem.',
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
}
