<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Users\ProvisionUserDefaults;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        app(ProvisionUserDefaults::class)->handle($user);
    }
}
