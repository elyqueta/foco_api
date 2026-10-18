<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Rules\DueDateFormat;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adiar tarefa (`POST /tasks/{id}/postpone`): exige o novo prazo **com data e
 * hora** (`YYYY-MM-DDTHH:mm`; aceita também espaço e segundos opcionais). A
 * data é validada pela regra partilhada (`DueDateFormat`) e `withTime()`
 * garante que a hora vem preenchida. As regras de hoje/futuro (G-08) são
 * aplicadas na action.
 */
class PostponeTaskRequest extends FormRequest
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
            'dueDate' => ['required', 'string', new DueDateFormat, $this->withTime()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dueDate.required' => 'Indique a nova data e hora de prazo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('dueDate') && is_string($this->input('dueDate'))) {
            $this->merge(['dueDate' => trim($this->input('dueDate'))]);
        }
    }

    /**
     * Adiar exige a **hora**: "2026-10-05" não conta, tem de ser
     * "2026-10-05T14:30" (ou com espaço). Aceita segundos opcionais.
     */
    protected function withTime(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', trim($value)) !== 1) {
                $fail('Para adiar, indique a data e a hora (AAAA-MM-DDTHH:mm).');
            }
        };
    }
}
