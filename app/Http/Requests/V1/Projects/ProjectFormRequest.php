<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Projects;

use App\Enums\ProjectStatus;
use App\Enums\Urgency;
use App\Rules\ValidCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Regras comuns aos pedidos de projeto (`StoreProjectRequest` e
 * `UpdateProjectRequest`). As mensagens e os valores aceites vivem aqui
 * para que criar e atualizar nunca divirjam.
 */
abstract class ProjectFormRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            // ValidCategory: comparado por name_key contra as categorias do
            // utilizador; o nome canónico é resolvido na action (doc 05 §5.3).
            'category' => ['sometimes', 'nullable', 'string', 'max:60', new ValidCategory],
            'urgency' => ['sometimes', 'string', Rule::in($this->urgencyValues())],
            'status' => ['sometimes', 'string', Rule::in($this->statusValues())],
            'canPostpone' => ['sometimes', 'boolean'],
            // Projetos só têm data (T-02); o front envia `type="date"`, mas
            // aceita-se ISO com hora e trunca-se na action. A validação é
            // explicita (formato + calendário): a regra `date` do Laravel
            // aceita "2026-10" ou "12 September 2026", que o PostgreSQL
            // rejeita ao escrever numa coluna `date`.
            'dueDate' => ['sometimes', 'nullable', 'string', $this->dueDateRule()],
            'nextStep' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.max' => 'A descrição é demasiado longa.',
            'category.max' => 'Máximo de 60 caracteres.',
            'urgency.in' => 'A urgência indicada não é válida.',
            'status.in' => 'O estado indicado não é válido.',
            'canPostpone.boolean' => 'Indique se o projeto pode ser adiado (verdadeiro ou falso).',
            'dueDate.date' => 'A data de prazo não é válida.',
            'nextStep.max' => 'Máximo de 255 caracteres.',
            'color.regex' => 'A cor deve estar no formato #RRGGBB (ex.: #6C5CE7).',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description', 'category', 'nextStep', 'color', 'urgency', 'status', 'dueDate'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * Reduz a data de prazo a `Y-m-d` (projetos não têm hora). Só corre
     * depois de `dueDateRule()`, pelo que o `substr` é sempre seguro.
     */
    protected function passedValidation(): void
    {
        $dueDate = $this->input('dueDate');

        if (is_string($dueDate) && trim($dueDate) !== '') {
            $this->merge(['dueDate' => substr(trim($dueDate), 0, 10)]);
        }
    }

    /**
     * Aceita `YYYY-MM-DD` ou ISO com hora e exige uma data de calendário
     * real ("2026-02-30" e "2026-13-45" são rejeitados).
     */
    protected function dueDateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', trim($value), $parts)) {
                $fail('A data de prazo não é válida.');

                return;
            }

            if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $fail('A data de prazo não é válida.');
            }
        };
    }

    /**
     * @return list<string>
     */
    protected function urgencyValues(): array
    {
        return array_map(
            fn (Urgency $urgency): string => $urgency->value,
            Urgency::cases(),
        );
    }

    /**
     * @return list<string>
     */
    protected function statusValues(): array
    {
        return array_map(
            fn (ProjectStatus $status): string => $status->value,
            ProjectStatus::cases(),
        );
    }
}
