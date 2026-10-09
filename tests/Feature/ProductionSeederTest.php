<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private const SEED_ENV_KEYS = [
        'SEED_ADMIN_NAME',
        'SEED_ADMIN_EMAIL',
        'SEED_ADMIN_PASSWORD',
        'SEED_ADMIN_RESET_PASSWORD',
        'SEED_DEMO_DATA',
    ];

    protected function tearDown(): void
    {
        foreach (self::SEED_ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function setSeedEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    #[Test]
    public function without_env_variables_creates_no_user(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function with_env_variables_creates_user_with_defaults(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Test Admin',
            'SEED_ADMIN_EMAIL' => 'test@admin.com',
            'SEED_ADMIN_PASSWORD' => 'password123',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $this->assertEquals(1, User::count());
        $user = User::first();
        $this->assertEquals('Test Admin', $user->name);
        $this->assertEquals('test@admin.com', $user->email);
        $this->assertTrue(Hash::check('password123', $user->password));

        $this->assertEquals(3, $user->categories()->count());
        $nameKeys = $user->categories()->pluck('name_key')->toArray();
        $this->assertContains('professional', $nameKeys);
        $this->assertContains('personal', $nameKeys);
        $this->assertContains('household', $nameKeys);

        $this->assertNotNull($user->notificationPreference);
        $this->assertTrue($user->notificationPreference->email_enabled);
        $this->assertTrue($user->notificationPreference->in_app_enabled);
        $this->assertEquals(8, $user->notificationPreference->digest_hour);
    }

    #[Test]
    public function running_twice_does_not_duplicate(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Test Admin',
            'SEED_ADMIN_EMAIL' => 'test@admin.com',
            'SEED_ADMIN_PASSWORD' => 'password123',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $this->assertEquals(1, User::count());
        $this->assertEquals(3, Category::count());
        $this->assertEquals(1, NotificationPreference::count());
    }

    #[Test]
    public function password_only_changes_with_reset_flag(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Test Admin',
            'SEED_ADMIN_EMAIL' => 'test@admin.com',
            'SEED_ADMIN_PASSWORD' => 'password123',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);
        $user = User::first();
        $originalPassword = $user->password;

        // Run again without reset flag — password must not change.
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Test Admin',
            'SEED_ADMIN_EMAIL' => 'test@admin.com',
            'SEED_ADMIN_PASSWORD' => 'password123',
        ]);
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);
        $user->refresh();
        $this->assertEquals($originalPassword, $user->password);

        // Run with reset flag — password changes.
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Test Admin',
            'SEED_ADMIN_EMAIL' => 'test@admin.com',
            'SEED_ADMIN_PASSWORD' => 'newpassword123',
            'SEED_ADMIN_RESET_PASSWORD' => 'true',
        ]);
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);
        $user->refresh();
        $this->assertNotEquals($originalPassword, $user->password);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    #[Test]
    public function missing_variables_log_a_warning_and_skip(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'SEED_ADMIN'));

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function demo_data_is_seeded_when_flag_is_true(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Zua',
            'SEED_ADMIN_EMAIL' => 'admin@todo.ao',
            'SEED_ADMIN_PASSWORD' => '12345678',
            'SEED_DEMO_DATA' => 'true',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $user = User::first();
        $this->assertEquals(2, $user->projects()->count());
        $this->assertEquals(4, $user->tasks()->count());
    }

    #[Test]
    public function database_seeder_creates_demo_user_in_local_or_testing(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => '',
            'SEED_ADMIN_EMAIL' => '',
            'SEED_ADMIN_PASSWORD' => '',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DatabaseSeeder']);

        $this->assertEquals(1, User::count());
        $user = User::first();
        $this->assertEquals('Zua', $user->name);
        $this->assertEquals('admin@todo.ao', $user->email);
        $this->assertTrue(Hash::check('12345678', $user->password));
    }

    #[Test]
    public function demo_data_is_created_for_the_seeded_user_and_not_another_admin(): void
    {
        $this->setSeedEnv([
            'SEED_ADMIN_NAME' => 'Outro',
            'SEED_ADMIN_EMAIL' => 'other@admin.com',
            'SEED_ADMIN_PASSWORD' => 'password123',
            'SEED_DEMO_DATA' => 'true',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\ProductionSeeder']);

        $user = User::whereEmail('other@admin.com')->firstOrFail();
        $this->assertEquals(2, $user->projects()->count());
        $this->assertEquals(4, $user->tasks()->count());
    }
}
