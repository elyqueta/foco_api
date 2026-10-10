<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Enums\Urgency;
use App\Models\User as AuthUser;
use App\Rules\DueDateFormat;
use App\Rules\ValidCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Regras comuns à criação e atualização de tarefas (`StoreTaskRequest` e
 * `UpdateTaskRequest`). Mensagens e valores aceites vivem aqui para que
 * criar e atualizar nunca divirjam. `title` e `status` (que divergem entre
 * criar e atualizar) são definidos nas subclasses.
 */
abstract class TaskFormRequest extends FormRequest
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
            // ValidCategory: comparada por name_key contra as categorias do
            // utilizador; o nome canónico é resolvido na action (doc 05 §5.3).
            'category' => ['sometimes', 'nullable', 'string', 'max:60', new ValidCategory],
            'urgency' => ['sometimes', 'string', Rule::in($this->urgencyValues())],
            'canPostpone' => ['sometimes', 'boolean'],
            'nextStep' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estimateMinutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:525600'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'projectId' => ['sometimes', 'nullable', 'string', $this->projectRule()],
            'dueDate' => ['sometimes', 'nullable', 'string', new DueDateFormat],
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
            'canPostpone.boolean' => 'Indique se a tarefa pode ser adiada (verdadeiro ou falso).',
            'estimateMinutes.integer' => 'A estimativa tem de ser um número inteiro de minutos.',
            'estimateMinutes.min' => 'A estimativa não pode ser negativa.',
            'estimateMinutes.max' => 'A estimativa é demasiado grande.',
            'nextStep.max' => 'Máximo de 255 caracteres.',
            'tags.max' => 'Máximo de 20 etiquetas.',
            'tags.*.max' => 'Cada etiqueta pode ter no máximo 40 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description', 'category', 'nextStep', 'urgency', 'dueDate'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * O `projectId` tem de ser um UUID de um projeto **do mesmo
     * utilizador** (isolamento entre utilizadores → 422). Vazio/`null` é
     * aceite para limpar a associação; a action decide o efeito.
     */
    protected function projectRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || ! Str::isUuid($value)) {
                $fail('O projeto indicado não é válido.');

                return;
            }

            /** @var AuthUser|null $user */
            $user = $this->user();

            if (! $user instanceof AuthUser || ! $user->projects()->whereKey($value)->exists()) {
                $fail('O projeto indicado não existe.');
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
}
