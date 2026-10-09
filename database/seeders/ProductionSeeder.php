<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Users\ProvisionUserDefaults;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ProductionSeeder extends Seeder
{
    /**
     * Seed the application's database safely for production.
     *
     * Reads SEED_ADMIN_NAME, SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD from the
     * environment. When any of them is missing nothing is created and a warning
     * is logged — never create a user with a default password in production.
     *
     * @param  string|null  $name  Overrides SEED_ADMIN_NAME (used by DatabaseSeeder).
     * @param  string|null  $email  Overrides SEED_ADMIN_EMAIL.
     * @param  string|null  $password  Overrides SEED_ADMIN_PASSWORD.
     */
    public function run(?string $name = null, ?string $email = null, ?string $password = null): void
    {
        $name = ($name !== null && $name !== '') ? $name : env('SEED_ADMIN_NAME');
        $email = ($email !== null && $email !== '') ? $email : env('SEED_ADMIN_EMAIL');
        $password = ($password !== null && $password !== '') ? $password : env('SEED_ADMIN_PASSWORD');

        if (! is_string($name) || $name === '' || ! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            Log::warning('ProductionSeeder: SEED_ADMIN_NAME, SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD are required; skipping admin creation.');

            return;
        }

        $resetPassword = filter_var(env('SEED_ADMIN_RESET_PASSWORD', false), FILTER_VALIDATE_BOOL);

        $attributes = ['name' => $name];

        if ($resetPassword || ! User::whereEmail($email)->exists()) {
            $attributes['password'] = Hash::make($password);
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        app(ProvisionUserDefaults::class)->handle($user);

        if (filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL) === true) {
            $this->callWith(DemoDataSeeder::class, ['user' => $user]);
        }
    }
}
