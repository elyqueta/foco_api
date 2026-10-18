<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        // ProvisionUserDefaults é chamado em AuthController::register dentro
        // da mesma transação, para evitar transação aninhada (savepoint)
        // que causaria 25P02 em caso de erro SQL interno.
    }
}
