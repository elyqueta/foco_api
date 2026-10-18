<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_user_with_defaults(): void
    {
        $this->artisan('foco:create-user', [
            'email' => 'newuser@test.com',
            '--name' => 'New User',
            '--password' => 'password123',
        ])->assertExitCode(0);

        $this->assertEquals(1, User::count());
        $user = User::first();
        $this->assertEquals('newuser@test.com', $user->email);
        $this->assertEquals('New User', $user->name);
        $this->assertTrue(Hash::check('password123', $user->password));

        $this->assertEquals(3, $user->categories()->count());
        $this->assertNotNull($user->notificationPreference);
    }

    #[Test]
    public function updates_existing_user(): void
    {
        User::factory()->create([
            'email' => 'existing@test.com',
            'name' => 'Old Name',
            'password' => Hash::make('oldpassword'),
        ]);

        $this->artisan('foco:create-user', [
            'email' => 'existing@test.com',
            '--name' => 'Updated Name',
            '--password' => 'newpassword123',
        ])->assertExitCode(0);

        $this->assertEquals(1, User::count());
        $user = User::first();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    #[Test]
    public function rejects_invalid_email(): void
    {
        $this->artisan('foco:create-user', [
            'email' => 'invalid-email',
            '--password' => 'password123',
        ])->assertExitCode(1);

        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function rejects_short_password(): void
    {
        $this->artisan('foco:create-user', [
            'email' => 'test@test.com',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function generates_name_from_email_when_not_provided(): void
    {
        $this->artisan('foco:create-user', [
            'email' => 'auto.name@test.com',
            '--password' => 'password123',
        ])->assertExitCode(0);

        $user = User::first();
        $this->assertEquals('auto.name', $user->name);
    }
}
