<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProvisionUserDefaults
{
    /**
     * Nomes iguais às chaves (`name_key`) para coincidir com os valores que o
     * front guarda em `task.category`/`project.category` (doc 00 §3).
     */
    public const DEFAULT_CATEGORIES = [
        ['name' => 'professional', 'name_key' => 'professional', 'is_default' => true],
        ['name' => 'personal', 'name_key' => 'personal', 'is_default' => true],
        ['name' => 'household', 'name_key' => 'household', 'is_default' => true],
    ];

    /**
     * Provision default categories and notification preferences for a user.
     * Idempotent: safe to call multiple times.
     *
     * `$protected = false` cria as categorias padrão elimináveis — usado no
     * auto-registo de utilizadores comuns. O `UserObserver` cria primeiro as
     * protegidas, por isso o registo reconcilia a flag com `updateOrCreate`
     * (o utilizador novo ainda não tem dados, a mudança é segura).
     */
    public function handle(User $user, bool $protected = true): void
    {
        DB::transaction(function () use ($user, $protected): void {
            foreach (self::DEFAULT_CATEGORIES as $category) {
                $user->categories()->updateOrCreate(
                    ['name_key' => $category['name_key']],
                    ['name' => $category['name'], 'is_default' => $protected],
                );
            }

            $user->notificationPreference()->firstOrCreate(
                ['user_id' => $user->id],
                NotificationPreference::defaultAttributes(),
            );
        });
    }
}
