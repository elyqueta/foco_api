<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProvisionUserDefaults
{
    public const DEFAULT_CATEGORIES = [
        ['name' => 'Professional', 'name_key' => 'professional', 'is_default' => true],
        ['name' => 'Pessoal', 'name_key' => 'personal', 'is_default' => true],
        ['name' => 'Casa', 'name_key' => 'household', 'is_default' => true],
    ];

    /**
     * Provision default categories and notification preferences for a user.
     * Idempotent: safe to call multiple times.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            foreach (self::DEFAULT_CATEGORIES as $category) {
                $user->categories()->firstOrCreate(
                    ['name_key' => $category['name_key']],
                    $category,
                );
            }

            $user->notificationPreference()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'email_enabled' => true,
                    'in_app_enabled' => true,
                    'digest_hour' => 8,
                    'due_soon_hours' => 24,
                    'due_imminent_minutes' => 60,
                    'timer_long_hours' => 4,
                    'types' => [],
                ],
            );
        });
    }
}
