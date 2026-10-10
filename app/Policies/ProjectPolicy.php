<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Qualquer utilizador autenticado pode criar os seus projetos.
     */
    public function create(User $user): bool
    {
        return $user->exists();
    }

    /**
     * Só o dono vê o projeto (regra 6: dado por utilizador). Recurso de
     * outro utilizador é 404, obtido pelo scoping da query.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Só o dono atualiza.
     */
    public function update(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Só o dono remove (e com ele as tarefas e o histórico).
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }
}
