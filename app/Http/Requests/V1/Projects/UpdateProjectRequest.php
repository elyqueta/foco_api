<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Projects;

class UpdateProjectRequest extends ProjectFormRequest
{
    /**
     * PATCH é parcial: o nome só é validado quando vem no pedido (doc 00
     * §"Regras transversais"). `validated()` devolve apenas os campos
     * enviados — a action aplica o diff.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            ...parent::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.min' => 'Mínimo de 2 caracteres.',
            'name.max' => 'Máximo de 255 caracteres.',
            ...parent::messages(),
        ];
    }
}
