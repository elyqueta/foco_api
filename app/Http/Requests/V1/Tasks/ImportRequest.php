<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Tasks;

use App\Actions\Tasks\ImportData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Importação de dados (doc 11 §"Importação"): valida os limites do pedido
 * (corpo ≤ 1 MB, ≤ 500 itens) antes da validação manual por campo no
 * `ImportData`.
 */
class ImportRequest extends FormRequest
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
            'projects' => ['sometimes', 'array'],
            'projects.*' => ['sometimes', 'array'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*' => ['sometimes', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $body = $this->getContent();
            $bodySize = strlen($body);

            if ($bodySize > ImportData::MAX_BODY_BYTES) {
                $validator->errors()->add(
                    'body',
                    'O corpo do pedido excede o limite de 1 MB.',
                );
            }

            $projects = $this->input('projects', []);
            $tasks = $this->input('tasks', []);

            if (! is_array($projects)) {
                $projects = [];
            }
            if (! is_array($tasks)) {
                $tasks = [];
            }

            if (count($projects) + count($tasks) > ImportData::MAX_ITEMS) {
                $validator->errors()->add(
                    'body',
                    'Limite de '.ImportData::MAX_ITEMS.' itens por pedido excedido.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $projects = $this->input('projects');
        $tasks = $this->input('tasks');

        if (! is_array($projects)) {
            $this->merge(['projects' => []]);
        }
        if (! is_array($tasks)) {
            $this->merge(['tasks' => []]);
        }
    }
}
