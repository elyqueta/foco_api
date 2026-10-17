<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Prompt para IA (doc 11 §"Prompt para IA"): devolve o texto do prompt mestre
 * do front, atualizado com as categorias reais do utilizador.
 *
 * Resposta: `{ "prompt": "..." }`.
 */
class ImportPromptController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $categories = $user->categories()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $categoryList = implode(', ', $categories);

        $prompt = 'Converte a lista de tarefas abaixo em JSON no formato do Foco. '
            .'As categorias válidas são: '.$categoryList.'. '
            .'Cada projeto deve ter: name (obrigatório, ≥ 2 caracteres), category (uma das acima), '
            .'urgency (critical/high/medium/low), dueDate (YYYY-MM-DD), nextStep, description, canPostpone, color. '
            .'Cada tarefa deve ter: title (≥ 2 caracteres), projectName ou projectId, category, urgency, '
            .'dueDate (YYYY-MM-DD, não pode ser no passado), nextStep, canPostpone, estimateMinutes, tags, description. '
            .'Responda apenas com o JSON.';

        return response()->json(['prompt' => $prompt]);
    }
}
