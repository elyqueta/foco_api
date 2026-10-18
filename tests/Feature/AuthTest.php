<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    /**
     * @return array{email: string, password: string}
     */
    private function credentials(): array
    {
        return [
            'email' => 'admin@todo.ao',
            'password' => '12345678',
        ];
    }

    private function user(): User
    {
        return User::factory()->create([
            'name' => 'Zua',
            'email' => 'admin@todo.ao',
            'password' => '12345678',
            'timezone' => 'Africa/Luanda',
            'theme' => 'light',
        ]);
    }

    /**
     * Em produção cada pedido corre num processo novo. Nos testes a aplicação
     * (e o guard Sanctum, com o utilizador já resolvido) é partilhada entre
     * pedidos, pelo que os guards têm de ser esquecidos antes de repetir um
     * pedido que depende do estado do token.
     */
    private function forgetAuthGuards(): void
    {
        $this->app->make('auth')->forgetGuards();
    }

    #[Test]
    public function login_returns_token_user_and_expires_at(): void
    {
        $user = $this->user();

        $response = $this->postJson('/api/v1/auth/login', $this->credentials());

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
                'expires_at',
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Zua')
            ->assertJsonPath('user.email', 'admin@todo.ao');

        $expected = now()->addMinutes((int) config('sanctum.expiration'))->getTimestamp();

        $this->assertEqualsWithDelta($expected, strtotime((string) $response->json('expires_at')), 5);
        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function login_token_grants_access_to_me(): void
    {
        $this->user();

        $token = (string) $this->postJson('/api/v1/auth/login', $this->credentials())->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'name' => 'Zua',
                'email' => 'admin@todo.ao',
            ]);
    }

    #[Test]
    public function login_validates_required_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    #[Test]
    public function login_rejects_invalid_email_and_short_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nao-e-email', 'password' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('errors.password.0', 'Mínimo de 8 caracteres.');
    }

    #[Test]
    public function login_with_wrong_password_returns_401(): void
    {
        $this->user();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@todo.ao',
            'password' => 'erradissima',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email ou palavra-passe incorretos.');
    }

    #[Test]
    public function login_with_unknown_email_returns_401_with_same_message(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'naoexiste@todo.ao',
            'password' => '12345678',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Email ou palavra-passe incorretos.');
    }

    #[Test]
    public function login_throttles_after_five_attempts(): void
    {
        $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $this->credentials())->assertOk();
        }

        $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiadas tentativas. Tenta novamente dentro de instantes.');
    }

    #[Test]
    public function me_returns_authenticated_user(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
    }

    #[Test]
    public function me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }

    #[Test]
    public function me_rejects_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expirado', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }

    #[Test]
    public function unauthenticated_request_without_accept_header_returns_json_401(): void
    {
        // Sem `Accept: application/json` o middleware de auth tentava
        // redireccionar para a rota `login` (inexistente) → 500. Regressão.
        $this->get('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }

    #[Test]
    public function password_update_rejects_same_password(): void
    {
        $user = User::factory()->create(['password' => '12345678']);

        $this->authenticateAs($user);

        $this->patchJson('/api/v1/auth/password', [
            'currentPassword' => '12345678',
            'password' => '12345678',
            'passwordConfirmation' => '12345678',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES')
            ->assertJsonPath('message', 'Nenhuma alteração detetada.');
    }

    #[Test]
    public function logout_revokes_current_token(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('message', 'Sessão terminada.');

        $this->assertSame(0, $user->tokens()->count());

        $this->forgetAuthGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }

    #[Test]
    public function logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();

        $current = $user->createToken('atual', ['*'])->plainTextToken;
        $other = $user->createToken('outro', ['*'])->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/auth/logout-all')->assertOk()->assertJsonPath('message', 'Sessão terminada em todos os dispositivos.');

        $this->assertSame(0, $user->tokens()->count());

        $this->forgetAuthGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$current])
            ->assertStatus(401);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$other])
            ->assertStatus(401);
    }

    #[Test]
    public function password_can_be_updated_and_other_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['password' => '12345678']);

        $current = $user->createToken('atual', ['*'])->plainTextToken;
        $other = $user->createToken('outro', ['*'])->plainTextToken;

        $this->withToken($current)->patchJson('/api/v1/auth/password', [
            'currentPassword' => '12345678',
            'password' => 'novaPasse123',
            'passwordConfirmation' => 'novaPasse123',
        ])->assertOk()->assertJsonPath('message', 'Palavra-passe alterada.');

        $this->assertTrue(Hash::check('novaPasse123', (string) $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());

        $this->forgetAuthGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$current])->assertOk();

        $this->forgetAuthGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$other])
            ->assertStatus(401);
    }

    #[Test]
    public function password_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => '12345678']);

        $this->authenticateAs($user);

        $this->patchJson('/api/v1/auth/password', [
            'currentPassword' => 'erradissima',
            'password' => 'novaPasse123',
            'passwordConfirmation' => 'novaPasse123',
        ])->assertStatus(422)->assertJsonValidationErrors(['currentPassword']);
    }

    #[Test]
    public function password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create(['password' => '12345678']);

        $this->authenticateAs($user);

        $this->patchJson('/api/v1/auth/password', [
            'currentPassword' => '12345678',
            'password' => 'novaPasse123',
            'passwordConfirmation' => 'diferente123',
        ])->assertStatus(422)->assertJsonValidationErrors(['passwordConfirmation']);
    }

    #[Test]
    public function password_validates_minimum_length(): void
    {
        $user = User::factory()->create(['password' => '12345678']);

        $this->authenticateAs($user);

        $this->patchJson('/api/v1/auth/password', [
            'currentPassword' => '12345678',
            'password' => '123',
            'passwordConfirmation' => '123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password'])
            ->assertJsonPath('errors.password.0', 'Mínimo de 8 caracteres.');
    }

    #[Test]
    public function password_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/password', [
            'currentPassword' => '12345678',
            'password' => 'novaPasse123',
            'passwordConfirmation' => 'novaPasse123',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }
}
