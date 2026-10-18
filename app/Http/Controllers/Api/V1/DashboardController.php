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

        $todayResource = TaskResource::collection($data['today']);
        $pendingResource = TaskResource::collection($data['pending']);
        $urgentResource = TaskResource::collection($data['urgent']);
        $focusTaskResource = $data['focusTask'] !== null
            ? new TaskResource($data['focusTask'])
            : null;
        $activeTimerResource = $data['activeTimer'] !== null
            ? new TaskResource($data['activeTimer'])
            : null;

        return response()->json([
            'today' => $todayResource->resolve(),
            'pending' => $pendingResource->resolve(),
            'urgent' => $urgentResource->resolve(),
            'nextSteps' => $data['nextSteps'],
            'statsByCategory' => $data['statsByCategory'],
            'completedThisWeek' => $data['completedThisWeek'],
            'focusTask' => $focusTaskResource?->resolve(),
            'activeTimer' => $activeTimerResource?->resolve(),
            'unreadNotifications' => $data['unreadNotifications'],
            'serverNow' => $data['serverNow'],
        ]);
    }
}
