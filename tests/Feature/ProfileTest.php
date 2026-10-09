<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function updates_name_only(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/auth/profile', ['name' => 'Nome Novo'])
            ->assertOk()->assertJsonPath('message', 'Perfil atualizado.');

        $this->assertSame('Nome Novo', $user->fresh()->name);
        $this->assertSame(0, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function updates_email_with_password_and_records_history(): void
    {
        $user = $this->actingAsUser(['email' => 'antes.da.mudanca@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'depois.da.mudanca@todo.ao',
            'currentPassword' => 'password',
        ])->assertOk()->assertJsonPath('message', 'Perfil atualizado.');

        $this->assertSame('depois.da.mudanca@todo.ao', $user->fresh()->email);

        $this->assertDatabaseHas('email_change_logs', [
            'user_id' => $user->id,
            'old_email' => 'antes.da.mudanca@todo.ao',
            'new_email' => 'depois.da.mudanca@todo.ao',
        ]);
    }

    #[Test]
    public function email_history_records_old_and_new_email_correctly(): void
    {
        $user = $this->actingAsUser(['email' => 'antes@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'depois@todo.ao',
            'currentPassword' => 'password',
        ])->assertOk()->assertJsonPath('message', 'Perfil atualizado.');

        $log = EmailChangeLog::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('antes@todo.ao', $log->old_email);
        $this->assertSame('depois@todo.ao', $log->new_email);
        $this->assertNotNull($log->ip_address);
    }

    #[Test]
    public function keeps_multiple_email_changes_in_order(): void
    {
        $user = $this->actingAsUser(['email' => 'um@todo.ao']);

        foreach (['dois@todo.ao', 'tres@todo.ao'] as $email) {
            $this->patchJson('/api/v1/auth/profile', [
                'email' => $email,
                'currentPassword' => 'password',
            ])->assertOk()->assertJsonPath('message', 'Perfil atualizado.');
        }

        $logs = EmailChangeLog::where('user_id', $user->id)->oldest('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('um@todo.ao', $logs[0]->old_email);
        $this->assertSame('dois@todo.ao', $logs[0]->new_email);
        $this->assertSame('dois@todo.ao', $logs[1]->old_email);
        $this->assertSame('tres@todo.ao', $logs[1]->new_email);
        $this->assertSame('tres@todo.ao', $user->fresh()->email);
    }

    #[Test]
    public function updates_name_and_email_in_one_request(): void
    {
        $user = $this->actingAsUser(['email' => 'antigo@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', [
            'name' => 'Nome e Email',
            'email' => 'combinado@todo.ao',
            'currentPassword' => 'password',
        ])->assertOk()->assertJsonPath('message', 'Perfil atualizado.');

        $this->assertSame('Nome e Email', $user->fresh()->name);
        $this->assertSame('combinado@todo.ao', $user->fresh()->email);
        $this->assertSame(1, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function email_change_requires_current_password(): void
    {
        $user = $this->actingAsUser(['email' => 'original@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', ['email' => 'sem.passe@todo.ao'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currentPassword'])
            ->assertJsonPath(
                'errors.currentPassword.0',
                'A palavra-passe atual é obrigatória para mudar o email.',
            );

        $this->assertSame('original@todo.ao', $user->fresh()->email);
    }

    #[Test]
    public function email_change_rejects_wrong_password(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'errada@todo.ao',
            'currentPassword' => 'nao-e-a-passe',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['currentPassword'])
            ->assertJsonPath('errors.currentPassword.0', 'A palavra-passe atual está incorreta.');

        $this->assertSame(0, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function email_change_rejects_email_of_another_user(): void
    {
        $user = $this->actingAsUser();

        User::factory()->create(['email' => 'ocupado@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'ocupado@todo.ao',
            'currentPassword' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Este email já está registado.');

        $this->assertSame(0, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function email_change_to_same_email_writes_no_history(): void
    {
        $user = $this->actingAsUser(['email' => 'igual@todo.ao']);

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'igual@todo.ao',
            'currentPassword' => 'password',
        ])->assertOk()->assertJsonPath('message', 'Perfil atualizado.');

        $this->assertSame(0, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function validates_fields(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/auth/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->patchJson('/api/v1/auth/profile', ['name' => 'a'])
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Mínimo de 2 caracteres.');

        $this->patchJson('/api/v1/auth/profile', [
            'email' => 'nao-e-email',
            'currentPassword' => 'password',
        ])->assertJsonPath('errors.email.0', 'O email não é válido.');

        $this->assertSame(0, EmailChangeLog::where('user_id', $user->id)->count());
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Sem Token'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }
}
