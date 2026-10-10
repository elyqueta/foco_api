<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Enums\TaskStatus;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends TaskFormRequest
{
    /**
     * Na criação o `status` só pode ser `todo|in_progress|postponed`
     * (doc 07): não se cria uma tarefa já `done` nem `expired`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in($this->createStatusValues())],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'O título da tarefa é obrigatório.',
            'title.min' => 'Mínimo de 2 caracteres.',
            'title.max' => 'Máximo de 255 caracteres.',
            'status.in' => 'O estado indicado não é válido.',
            ...parent::messages(),
        ];
    }

    /**
     * @return list<string>
     */
    private function createStatusValues(): array
    {
        return [
            TaskStatus::Todo->value,
            TaskStatus::InProgress->value,
            TaskStatus::Postponed->value,
        ];
    }
}
