<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Projects;

use App\Enums\ProjectStatus;
use App\Support\PageResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros da listagem de projetos: `category`, `status` e `q`. A listagem é
 * paginada com `page` (≥ 1) e `perPage` (1…100, 20 por omissão).
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
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PageResponse::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'O estado indicado não é válido.',
            'page.min' => 'A página tem de ser 1 ou superior.',
            'perPage.max' => 'Cada página pode ter no máximo '.PageResponse::MAX_PER_PAGE.' projetos.',
            'perPage.min' => 'Cada página tem de ter pelo menos 1 projeto.',
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
