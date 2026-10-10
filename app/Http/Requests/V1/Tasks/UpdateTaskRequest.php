<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Enums\TaskStatus;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends TaskFormRequest
{
    /**
     * PATCH parcial: campos ausentes não mudam. O `status` aceita qualquer
     * valor do enum — inclusive `expired`, que passa a validação mas é
     * rejeitado pela máquina de estados com `422 INVALID_TRANSITION` (G-07),
     * que é a mensagem que o front deve apresentar.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in($this->statusValues())],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.min' => 'Mínimo de 2 caracteres.',
            'title.max' => 'Máximo de 255 caracteres.',
            'status.in' => 'O estado indicado não é válido.',
            ...parent::messages(),
        ];
    }

    /**
     * @return list<string>
     */
    private function statusValues(): array
    {
        return array_map(
            fn (TaskStatus $status): string => $status->value,
            TaskStatus::cases(),
        );
    }
}
