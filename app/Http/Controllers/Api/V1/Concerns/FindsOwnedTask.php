<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolução de tarefas sempre dentro do utilizador autenticado (regra 6):
 * tarefa de outro utilizador devolve `404 Não encontrado.`. Partilhado pelos
 * controllers de tarefas e de timer, para não duplicar a regra.
 */
trait FindsOwnedTask
{
    private function findTask(Request $request, string $id): Task
    {
        if (! Str::isUuid($id)) {
            throw new ModelNotFoundException;
        }

        /** @var User $user */
        $user = $request->user();

        $task = $user->tasks()->whereKey($id)->first();

        if (! $task instanceof Task) {
            throw new ModelNotFoundException;
        }

        $this->authorize('view', $task);

        return $task;
    }
}
