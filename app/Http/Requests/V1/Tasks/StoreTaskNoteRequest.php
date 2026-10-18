<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nota da tarefa (`POST /tasks/{id}/notes`): 1–2000 caracteres.
 */
class StoreTaskNoteRequest extends FormRequest
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
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'O texto da nota é obrigatório.',
            'text.min' => 'A nota não pode estar vazia.',
            'text.max' => 'Máximo de 2000 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('text') && is_string($this->input('text'))) {
            $this->merge(['text' => trim($this->input('text'))]);
        }
    }
}
