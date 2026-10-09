<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    /**
     * @return array{name: string, email: string, password: string, passwordConfirmation: string}
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Utilizador Novo',
            'email' => 'novo@todo.ao',
            'password' => '12345678',
            'passwordConfirmation' => '12345678',
            ...$overrides,
        ];
    }

    #[Test]
    public function register_returns_token_user_and_expires_at(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
                'expires_at',
            ])
            ->assertJsonPath('user.name', 'Utilizador Novo')
            ->assertJsonPath('user.email', 'novo@todo.ao');

        $this->assertDatabaseHas('users', [
            'name' => 'Utilizador Novo',
            'email' => 'novo@todo.ao',
        ]);
    }

    #[Test]
    public function register_password_is_hashed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());

        $user = User::whereEmail('novo@todo.ao')->firstOrFail();

        $this->assertNotSame('12345678', $user->password);
    }

    #[Test]
    public function register_provisions_deletable_default_categories_and_no_data(): void
    {
        $userId = $this->postJson('/api/v1/auth/register', $this->payload())->json('user.id');

        $categories = Category::where('user_id', $userId)->get();

        $this->assertCount(3, $categories);
        $this->assertSame(
            ['household', 'personal', 'professional'],
            $categories->sortBy('name')->pluck('name')->all(),
        );
        $this->assertTrue(
            $categories->every(fn (Category $category): bool => $category->is_default === false),
            'As categorias padrão do auto-registo têm de ser elimináveis.',
        );

        $this->assertSame(0, Task::where('user_id', $userId)->count());
        $this->assertSame(0, Project::where('user_id', $userId)->count());
    }

    #[Test]
    public function register_token_grants_access_to_me(): void
    {
        $token = (string) $this->postJson('/api/v1/auth/register', $this->payload())->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJson([
                'name' => 'Utilizador Novo',
                'email' => 'novo@todo.ao',
            ]);
    }

    #[Test]
    public function register_validates_payload(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'passwordConfirmation']);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'nao-e-email']))
            ->assertJsonPath('errors.email.0', 'O email não é válido.');

        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => '123',
            'passwordConfirmation' => '123',
        ]))->assertJsonPath('errors.password.0', 'Mínimo de 8 caracteres.');

        $this->postJson('/api/v1/auth/register', $this->payload(['passwordConfirmation' => 'diferente123']))
            ->assertJsonPath('errors.passwordConfirmation.0', 'As palavras-passe não coincidem.');

        $this->postJson('/api/v1/auth/register', $this->payload(['name' => 'a']))
            ->assertJsonPath('errors.name.0', 'Mínimo de 2 caracteres.');
    }

    #[Test]
    public function register_rejects_duplicated_email(): void
    {
        $this->actingAsUser(['email' => 'novo@todo.ao', 'name' => 'Existente']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Este email já está registado.');
    }

    #[Test]
    public function register_throttles_after_five_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', $this->payload(['email' => "user{$attempt}@todo.ao"]))
                ->assertStatus(201);
        }

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'sexto@todo.ao']))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Demasiadas tentativas. Tenta novamente dentro de instantes.');
    }

    #[Test]
    public function registered_user_can_delete_default_categories(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $user = User::whereEmail('novo@todo.ao')->firstOrFail();

        $this->authenticateAs($user);

        $this->deleteJson('/api/v1/categories/professional')->assertOk()->assertJsonPath('message', 'Categoria removida.');
        $this->deleteJson('/api/v1/categories/personal')->assertOk()->assertJsonPath('message', 'Categoria removida.');

        $this->assertSame(['household'], $user->categories()->pluck('name')->all());
    }

    #[Test]
    public function registered_user_deleting_category_reassigns_items_to_another_one(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $user = User::whereEmail('novo@todo.ao')->firstOrFail();

        $task = Task::factory()->create(['user_id' => $user->id, 'category' => 'professional']);
        $project = Project::factory()->create(['user_id' => $user->id, 'category' => 'professional']);

        $this->authenticateAs($user);

        $this->deleteJson('/api/v1/categories/professional')->assertOk()->assertJsonPath('message', 'Categoria removida.');

        $this->assertNotSame('professional', $task->fresh()->category);
        $this->assertContains($task->fresh()->category, ['household', 'personal']);
        $this->assertNotSame('professional', $project->fresh()->category);

        $this->assertDatabaseHas('activity_entries', [
            'user_id' => $user->id,
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'type' => 'edited',
            'message' => 'Categoria alterada para '.$task->fresh()->category,
        ]);
    }

    #[Test]
    public function deleting_the_last_category_with_items_keeps_a_valid_category(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $user = User::whereEmail('novo@todo.ao')->firstOrFail();

        Task::factory()->create(['user_id' => $user->id, 'category' => 'professional']);

        $this->authenticateAs($user);

        $this->deleteJson('/api/v1/categories/household')->assertOk()->assertJsonPath('message', 'Categoria removida.');
        $this->deleteJson('/api/v1/categories/personal')->assertOk()->assertJsonPath('message', 'Categoria removida.');
        $this->deleteJson('/api/v1/categories/professional')->assertOk()->assertJsonPath('message', 'Categoria removida.');

        $categories = $user->categories()->get();

        $this->assertCount(1, $categories);
        $this->assertSame('professional', $categories->first()->name);
        $this->assertFalse($categories->first()->is_default);
        $this->assertSame('professional', $user->tasks()->firstOrFail()->category);
    }
}
