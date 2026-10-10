<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Timer;

use App\Actions\Timer\PauseTimer;
use App\Actions\Timer\StartTimer;
use App\Enums\TimerState;
use App\Http\Controllers\Api\V1\Concerns\FindsOwnedTask;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TaskResource;
use App\Http\Resources\V1\TimeEntryResource;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Timer (doc 08): início, pausa, retoma, entradas de tempo e tarefa activa.
 *
 *  - `start`/`resume` partilham a mesma Action (`StartTimer`); a diferença é
 *    o estado de partida exigido (`idle` vs `paused`).
 *  - Respostas: `start`/`resume` → `{ task, pausedTask? }`; `pause` →
 *    `{ task }`; `active` → `{ task }` ou `204`.
 */
class TimerController extends Controller
{
    use FindsOwnedTask;

    /**
     * Tecto do histórico de entradas devolvido (a API não pagina na v1).
     */
    private const ENTRIES_LIMIT = 100;

    /**
     * `POST /tasks/{id}/timer/start` — exige `idle`.
     */
    public function start(Request $request, string $id): JsonResponse
    {
        return $this->run($request, $id, 'start');
    }

    /**
     * `POST /tasks/{id}/timer/resume` — exige `paused`.
     */
    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->run($request, $id, 'resume');
    }

    /**
     * `POST /tasks/{id}/timer/pause` — fecha a entrada aberta.
     */
    public function pause(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, $id);

        $this->authorize('update', $task);

        $task = app(PauseTimer::class)->handle($user, $task);

        return response()->json(['task' => $this->task($task, $user)]);
    }

    /**
     * `GET /tasks/{id}/time-entries` — entradas da tarefa, mais recente
     * primeiro (`{ id, startedAt, endedAt, endedReason, seconds }`).
     *
     * Query directa em vez da relação `timeEntries()`: ela traz
     * `orderBy('started_at')` ascendente e a ordem explícita daqui ficaria
     * depois dela no SQL. A pertença da tarefa já foi verificada pelo
     * `findTask`.
     */
    public function entries(Request $request, string $id): JsonResponse
    {
        $task = $this->findTask($request, $id);

        $entries = TaskTimeEntry::query()
            ->where('task_id', $task->id)
            ->latest('started_at')
            ->limit(self::ENTRIES_LIMIT)
            ->get();

        return TimeEntryResource::collection($entries)->response();
    }

    /**
     * `GET /timer/active` — a tarefa com o timer a correr do utilizador, ou
     * `200` com `task: null` e uma mensagem PT quando nenhuma está a correr
     * (pedido do utilizador: um `204` sem corpo não diz nada ao front).
     */
    public function active(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $entry = app(TimerService::class)->openForUser($user);

        if (! $entry instanceof TaskTimeEntry || ! $entry->task instanceof Task) {
            return response()->json([
                'task' => null,
                'message' => 'Nenhum timer a correr.',
            ]);
        }

        $task = $entry->task;

        // A relação aberta evita uma query extra no recurso (o timer está a
        // correr nesta entrada).
        $task->setRelation('activeTimeEntry', $entry);
        $task->setRelation('user', $user);

        return response()->json(['task' => TaskResource::make($task)]);
    }

    /**
     * @return array{task: Task, pausedTask: Task|null}
     */
    private function run(Request $request, string $id, string $mode): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, $id);

        $this->authorize('update', $task);

        // Já está a correr nesta tarefa: conflito amigável — devolve a tarefa
        // (não é erro de input, é o estado actual do recurso).
        if (app(TimerService::class)->state($task) === TimerState::Running) {
            return response()->json([
                'message' => StartTimer::ALREADY_RUNNING_MESSAGE,
                'code' => StartTimer::ALREADY_RUNNING_CODE,
                'task' => $this->task($task, $user),
            ], 409);
        }

        $result = app(StartTimer::class)->handle($user, $task, $mode);

        $payload = ['task' => $this->task($result['task'], $user)];

        // `pausedTask` só aparece quando outra tarefa foi pausada
        // automaticamente (mesmo padrão do `suggestCompleteProject`).
        if ($result['pausedTask'] instanceof Task) {
            $payload['pausedTask'] = $this->task($result['pausedTask'], $user);
        }

        return response()->json($payload);
    }

    private function task(Task $task, ?User $user): TaskResource
    {
        if ($user instanceof User) {
            $task->setRelation('user', $user);
        }

        return TaskResource::make($task);
    }
}
