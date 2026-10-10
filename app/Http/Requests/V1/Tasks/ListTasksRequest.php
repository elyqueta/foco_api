<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Enums\TaskStatus;
use App\Enums\Urgency;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Filtros da listagem de tarefas (doc 07), todos opcionais: `category`,
 * `urgency`, `status` (um ou vários separados por vírgula), `projectId`
 * (UUID do utilizador ou `none`), `q`, `dueFrom`/`dueTo` (`YYYY-MM-DD`),
 * `includeDone`/`includeExpired` (booleans, `false` por omissão) e `sort`
 * (`urgency|dueDate|createdAt`).
 */
class ListTasksRequest extends FormRequest
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
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'urgency' => ['sometimes', 'nullable', 'string', Rule::in($this->urgencyValues())],
            'status' => ['sometimes', 'nullable', 'string', $this->statusListRule()],
            'projectId' => ['sometimes', 'nullable', 'string', $this->projectFilterRule()],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dueFrom' => ['sometimes', 'nullable', 'string', $this->dateRule()],
            'dueTo' => ['sometimes', 'nullable', 'string', $this->dateRule()],
            'includeDone' => ['sometimes', 'string', 'in:true,false,0,1'],
            'includeExpired' => ['sometimes', 'string', 'in:true,false,0,1'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['urgency', 'dueDate', 'createdAt'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'urgency.in' => 'A urgência indicada não é válida.',
            'includeDone.in' => 'Valor inválido para includeDone (use true ou false).',
            'includeExpired.in' => 'Valor inválido para includeExpired (use true ou false).',
            'sort.in' => 'A ordenação indicada não é válida.',
        ];
    }

    /**
     * Lista de estados separados por vírgula; cada token tem de ser um
     * `TaskStatus` válido.
     */
    protected function statusListRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $valid = array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases());

            foreach (explode(',', $value) as $token) {
                if (! in_array(trim($token), $valid, true)) {
                    $fail('O estado indicado não é válido.');

                    return;
                }
            }
        };
    }

    /**
     * `projectId` no filtro é `none` (tarefas soltas) ou um UUID — validar o
     * formato evita erro de sintaxe do PostgreSQL na coluna `uuid`. A posse
     * não é validada aqui: um projectId de outro utilizador devolve lista
     * vazia (é filtro, não erro de recurso).
     */
    protected function projectFilterRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '' || ! is_string($value)) {
                return;
            }

            if ($value !== 'none' && ! Str::isUuid($value)) {
                $fail('O filtro de projeto não é válido.');
            }
        };
    }

    protected function dateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $value = trim($value);

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail('A data indicada não é válida.');
            }
        };
    }

    /**
     * @return list<string>
     */
    private function urgencyValues(): array
    {
        return array_map(
            fn (Urgency $urgency): string => $urgency->value,
            Urgency::cases(),
        );
    }
}
