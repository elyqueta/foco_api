<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tasks\DashboardQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TaskResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard (doc 11 §"Dashboard"): listas computadas do dia, estatísticas por
 * categoria, tarefas urgentes e tarefa de foco. Corre a expiração preguiçosa
 * antes de calcular.
 *
 * Resposta: `{ today, pending, urgent, nextSteps, statsByCategory,
 * completedThisWeek, focusTask, activeTimer, unreadNotifications, serverNow }`.
 *
 * `today` / `pending` / `urgent` devolvem tarefas sem `activity`.
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $query->forUser($user);

        return response()->json([
            'today' => TaskResource::collection($data['today'])->resolve(),
            'pending' => TaskResource::collection($data['pending'])->resolve(),
            'urgent' => TaskResource::collection($data['urgent'])->resolve(),
            'nextSteps' => $data['nextSteps'],
            'statsByCategory' => $data['statsByCategory'],
            'completedThisWeek' => $data['completedThisWeek'],
            'focusTask' => $data['focusTask'] !== null ? new TaskResource($data['focusTask'])->resolve() : null,
            'activeTimer' => $data['activeTimer'] !== null ? new TaskResource($data['activeTimer'])->resolve() : null,
            'unreadNotifications' => $data['unreadNotifications'],
            'serverNow' => $data['serverNow'],
        ]);
    }
}
