<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Qualquer utilizador autenticado pode criar as suas categorias.
     */
    public function create(User $user): bool
    {
        return $user->exists();
    }

    /**
     * Só o dono remove (regra 6 do AGENTS: dado por utilizador).
     */
    public function delete(User $user, Category $category): bool
    {
        return $category->user_id === $user->id;
    }
}
