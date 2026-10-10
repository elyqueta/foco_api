<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Projects;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da listagem de projetos: `category`, `status` e `q`.
 */
class ListProjectsRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in($this->statusValues())],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'O estado indicado não é válido.',
        ];
    }

    /**
     * @return list<string>
     */
    private function statusValues(): array
    {
        return array_map(
            fn (ProjectStatus $status): string => $status->value,
            ProjectStatus::cases(),
        );
    }
}
