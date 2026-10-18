<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros da listagem de notificações (doc 09 §"Endpoints"): `unread`
 * (só não lidas), `limit` (1…100, 50 por omissão) e `before` (UUID da
 * última notificação da página anterior, para paginar para trás).
 */
class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'string', 'in:true,false,0,1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'before' => ['sometimes', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unread.in' => 'Valor inválido para unread (use true ou false).',
            'limit.integer' => 'O limite tem de ser um número inteiro.',
            'limit.min' => 'O limite tem de ser de pelo menos 1 notificação.',
            'limit.max' => 'Cada página pode ter no máximo 100 notificações.',
            'before.uuid' => 'A notificação de referência não é válida.',
        ];
    }

    public function unreadOnly(): bool
    {
        return in_array($this->input('unread'), [true, 1, '1', 'true'], true);
    }
}
