<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Categories;

use App\Actions\Categories\ResolveCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome da categoria é obrigatório.',
            'name.min' => 'Mínimo de 2 caracteres.',
            'name.max' => 'Máximo de 60 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $name = trim((string) $this->input('name'));

            if ($name === '') {
                return;
            }

            $user = $this->user();

            if (! $user instanceof User) {
                return;
            }

            if (app(ResolveCategory::class)->handle($user, $name) !== null) {
                $validator->errors()->add('name', 'Esta categoria já existe.');
            }
        });
    }
}
