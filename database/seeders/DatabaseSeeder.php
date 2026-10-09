<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Delegates to ProductionSeeder. In local/testing without SEED_ADMIN_*
     * variables, falls back to the demo user used by the front-end
     * (admin@todo.ao / 12345678 / Zua) — never in production.
     */
    public function run(): void
    {
        $name = env('SEED_ADMIN_NAME');
        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');

        if (app()->environment('local', 'testing')) {
            $name = (is_string($name) && $name !== '') ? $name : 'Zua';
            $email = (is_string($email) && $email !== '') ? $email : 'admin@todo.ao';
            $password = (is_string($password) && $password !== '') ? $password : '12345678';
        }

        $this->callWith(ProductionSeeder::class, [$name, $email, $password]);
    }
}
