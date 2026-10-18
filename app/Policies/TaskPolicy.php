<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Qualquer utilizador autenticado pode criar as suas tarefas.
     */
    public function create(User $user): bool
    {
        return $user->exists();
    }

    /**
     * Só o dono vê a tarefa (regra 6: dado por utilizador). Tarefa de outro
     * utilizador é 404, obtido pelo scoping da query.
     */
    public function view(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }

    /**
     * Só o dono atualiza.
     */
    public function update(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }

    /**
     * Só o dono remove (e com ela o tempo e o histórico).
     */
    public function delete(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }
}
