<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Timer;

use App\Actions\Timer\TimeSummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Timer\TimeSummaryRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Resumo de tempo (`GET /api/v1/time/summary`, doc 08): totais agregados por
 * dia, tarefa ou categoria, com as entradas divididas pela meia-noite do fuso
 * do utilizador. Sem parâmetros, os últimos 7 dias por dia.
 */
class TimeSummaryController extends Controller
{
    public function index(TimeSummaryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(app(TimeSummary::class)->for(
            $user,
            is_string($request->validated('from')) ? $request->validated('from') : null,
            is_string($request->validated('to')) ? $request->validated('to') : null,
            (string) $request->validated('groupBy'),
        ));
    }
}
