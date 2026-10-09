<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class UpdatePasswordRequest extends FormRequest
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
            'currentPassword' => ['required', 'string'],
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
            'currentPassword.required' => 'A palavra-passe atual é obrigatória.',
            'password.required' => 'A nova palavra-passe é obrigatória.',
            'password.min' => 'Mínimo de 8 caracteres.',
            'passwordConfirmation.required' => 'A confirmação da palavra-passe é obrigatória.',
            'passwordConfirmation.same' => 'As palavras-passe não coincidem.',
        ];
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
