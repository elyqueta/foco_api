<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Rules\ValidCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function list_returns_the_three_default_categories_first(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJson([
                ['name' => 'household', 'isDefault' => true, 'tasksCount' => 0, 'projectsCount' => 0],
                ['name' => 'personal', 'isDefault' => true, 'tasksCount' => 0, 'projectsCount' => 0],
                ['name' => 'professional', 'isDefault' => true, 'tasksCount' => 0, 'projectsCount' => 0],
            ]);

        $names = array_column($this->getJson('/api/v1/categories')->assertOk()->json(), 'name');

        $this->assertSame(['household', 'personal', 'professional'], $names);
    }

    #[Test]
    public function list_orders_custom_categories_alphabetically_after_defaults(): void
    {
        $user = $this->actingAsUser();

        $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);
        $user->categories()->create(['name' => 'Aulas', 'name_key' => 'aulas', 'is_default' => false]);

        $names = array_column($this->getJson('/api/v1/categories')->assertOk()->json(), 'name');

        $this->assertSame(['household', 'personal', 'professional', 'Aulas', 'Estudos'], $names);
    }

    #[Test]
    public function store_creates_category_with_contract_shape(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/categories', ['name' => 'Estudos'])
            ->assertStatus(201)
            ->assertJson([
                'name' => 'Estudos',
                'isDefault' => false,
                'tasksCount' => 0,
                'projectsCount' => 0,
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Estudos',
            'name_key' => 'estudos',
            'is_default' => false,
        ]);
    }

    #[Test]
    public function store_trims_edges_and_collapses_key(): void
    {
        $this->actingAsUser();

        // O nome guardado é o texto com espaços nas pontas removidos; a
        // comparação (name_key) colapsa espaços internos e minúsculas.
        $this->postJson('/api/v1/categories', ['name' => '  Estudos   Avançados  '])
            ->assertStatus(201)
            ->assertJson([
                'name' => 'Estudos   Avançados',
                'isDefault' => false,
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'Estudos   Avançados',
            'name_key' => 'estudos avançados',
        ]);
    }

    #[Test]
    public function store_rejects_duplicated_name(): void
    {
        $user = $this->actingAsUser();

        $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $this->postJson('/api/v1/categories', ['name' => 'estudos'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Esta categoria já existe.');

        $this->postJson('/api/v1/categories', ['name' => 'ESTUDOS'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Esta categoria já existe.');
    }

    #[Test]
    public function store_rejects_duplicated_default_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/categories', ['name' => 'professional'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Esta categoria já existe.');
    }

    #[Test]
    public function store_validates_name_length(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/categories', ['name' => 'a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Mínimo de 2 caracteres.');

        $this->postJson('/api/v1/categories', ['name' => str_repeat('a', 61)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'Máximo de 60 caracteres.');
    }

    #[Test]
    public function store_requires_name(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function list_counts_tasks_and_projects_per_user(): void
    {
        $userA = $this->actingAsUser();

        $category = $userA->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        Task::factory()->count(2)->create(['user_id' => $userA->id, 'category' => $category->name]);
        Task::factory()->create(['user_id' => $userA->id, 'category' => 'professional']);
        Project::factory()->create(['user_id' => $userA->id, 'category' => $category->name]);

        $this->switchTo(User::factory()->create());

        $categories = collect($this->getJson('/api/v1/categories')->assertOk()->json());

        $estudos = $categories->firstWhere('name', 'Estudos');
        $professional = $categories->firstWhere('name', 'professional');

        $this->assertNull($estudos, 'As categorias de outro utilizador não podem ser listadas.');
        $this->assertSame(0, $professional['tasksCount']);
        $this->assertSame(0, $professional['projectsCount']);

        $this->switchTo($userA);

        $categories = collect($this->getJson('/api/v1/categories')->assertOk()->json());

        $this->assertSame(2, $categories->firstWhere('name', 'Estudos')['tasksCount']);
        $this->assertSame(1, $categories->firstWhere('name', 'Estudos')['projectsCount']);
        $this->assertSame(1, $categories->firstWhere('name', 'professional')['tasksCount']);
        $this->assertSame(0, $categories->firstWhere('name', 'professional')['projectsCount']);
    }

    #[Test]
    public function destroy_protects_default_categories(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/v1/categories/professional')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_PROTECTED')
            ->assertJsonPath('message', 'As categorias padrão não podem ser removidas.');

        $this->assertDatabaseHas('categories', ['name_key' => 'professional']);
    }

    #[Test]
    public function destroy_reassigns_tasks_and_projects_and_logs_activity(): void
    {
        $user = $this->actingAsUser();

        $category = $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $task = Task::factory()->create(['user_id' => $user->id, 'category' => $category->name]);
        $project = Project::factory()->create(['user_id' => $user->id, 'category' => $category->name]);

        $this->deleteJson('/api/v1/categories/Estudos')
            ->assertOk()->assertJsonPath('message', 'Categoria removida.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'category' => 'professional']);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'category' => 'professional']);

        $this->assertDatabaseHas('activity_entries', [
            'user_id' => $user->id,
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'type' => 'edited',
            'message' => 'Categoria alterada para professional',
        ]);

        $this->assertDatabaseHas('activity_entries', [
            'user_id' => $user->id,
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'type' => 'edited',
            'message' => 'Categoria alterada para professional',
        ]);
    }

    #[Test]
    public function destroy_resolves_name_case_and_space_insensitively(): void
    {
        $user = $this->actingAsUser();

        $user->categories()->create(['name' => 'Estudos Avançados', 'name_key' => 'estudos avançados', 'is_default' => false]);

        $this->deleteJson('/api/v1/categories/estudos%20AVAN%C3%87ADOS')->assertOk()->assertJsonPath('message', 'Categoria removida.');

        $this->assertDatabaseMissing('categories', ['name_key' => 'estudos avançados']);
    }

    #[Test]
    public function destroy_missing_category_returns_404_in_portuguese(): void
    {
        $this->actingAsUser();

        $this->deleteJson('/api/v1/categories/nao-existe')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Não encontrado.');
    }

    #[Test]
    public function users_cannot_delete_categories_of_other_users(): void
    {
        $userA = $this->actingAsUser();

        $category = $userA->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $this->switchTo(User::factory()->create());

        $this->deleteJson('/api/v1/categories/Estudos')
            ->assertStatus(404);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    #[Test]
    public function endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/categories')->assertStatus(401)->assertJsonPath('message', 'Não autenticado.');
        $this->postJson('/api/v1/categories', ['name' => 'Estudos'])->assertStatus(401);
        $this->deleteJson('/api/v1/categories/Estudos')->assertStatus(401);
    }

    #[Test]
    public function name_key_normalization(): void
    {
        $this->assertSame('estudos avançados', Category::nameKey('Estudos   Avançados'));
        $this->assertSame('casa', Category::nameKey('  CASA '));
        $this->assertSame('', Category::nameKey('   '));
    }

    #[Test]
    public function valid_category_rule_accepts_own_category_and_rejects_unknown(): void
    {
        $user = $this->actingAsUser();
        $this->be($user);

        $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $failures = [];
        $fail = function (string $message) use (&$failures): void {
            $failures[] = $message;
        };

        (new ValidCategory)->validate('category', 'estudos', $fail);
        $this->assertSame([], $failures);

        (new ValidCategory)->validate('category', 'Inexistente', $fail);
        $this->assertSame(['A categoria indicada não existe.'], $failures);
    }

    private function switchTo(User $user): void
    {
        $this->authenticateAs($user);
        $this->app->make('auth')->forgetGuards();
    }
}
