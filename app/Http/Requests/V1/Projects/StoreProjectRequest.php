<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Projects;

class StoreProjectRequest extends ProjectFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do projeto é obrigatório.',
            'name.min' => 'Mínimo de 2 caracteres.',
            'name.max' => 'Máximo de 255 caracteres.',
            ...parent::messages(),
        ];
    }
}
