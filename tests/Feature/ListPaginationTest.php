<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Support\PageResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

/**
 * Paginação e totais das listas (`GET /tasks` e `GET /projects`), pedido do
 * utilizador: a v1 devolvia arrays simples com tecto de 500 linhas.
 */
class ListPaginationTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    // ---- tarefas ---------------------------------------------------------

    #[Test]
    public function tasks_are_paginated_and_carry_the_total(): void
    {
        $user = $this->actingAsUser();

        Task::factory()->count(5)->create([
            'user_id' => $user->id,
            'status' => 'todo',
            'due_date' => null,
        ]);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJson([
                'page' => 1,
                'perPage' => PageResponse::DEFAULT_PER_PAGE,
                'total' => 5,
                'totalPages' => 1,
            ])
            ->assertJsonCount(5, 'items')
            ->assertJsonStructure([
                'items' => [
                    ['id', 'title', 'status', 'timer'],
                ],
                'page',
                'perPage',
                'total',
                'totalPages',
                'counts' => ['todo', 'in_progress', 'done', 'postponed', 'expired'],
            ]);
    }

    #[Test]
    public function tasks_accept_page_and_per_page(): void
    {
        $user = $this->actingAsUser();

        Task::factory()->count(25)->create([
            'user_id' => $user->id,
            'status' => 'todo',
            'due_date' => null,
        ]);

        $this->getJson('/api/v1/tasks?perPage=10&page=3')
            ->assertOk()
            ->assertJson(['page' => 3, 'perPage' => 10, 'total' => 25, 'totalPages' => 3])
            ->assertJsonCount(5, 'items');
    }

    #[Test]
    public function task_counts_ignore_the_status_filter_but_keep_the_others(): void
    {
        $user = $this->actingAsUser();

        Task::factory()->count(2)->create(['user_id' => $user->id, 'status' => 'todo', 'due_date' => null]);
        Task::factory()->create(['user_id' => $user->id, 'status' => 'in_progress', 'due_date' => null]);
        Task::factory()->done()->create(['user_id' => $user->id, 'due_date' => null]);
        Task::factory()->expired()->create(['user_id' => $user->id]);

        // Com `status=done` a lista só traz a concluída, mas as contagens
        // mantêm todos os estados (para o front desenhar os separadores).
        $this->getJson('/api/v1/tasks?status=done&includeDone=true')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('counts', [
                'todo' => 2,
                'in_progress' => 1,
                'done' => 1,
                'postponed' => 0,
                'expired' => 1,
            ])
            ->assertJson(['total' => 1, 'perPage' => PageResponse::DEFAULT_PER_PAGE]);
    }

    #[Test]
    public function task_counts_follow_the_other_filters(): void
    {
        $user = $this->actingAsUser();

        Task::factory()->create(['user_id' => $user->id, 'status' => 'todo', 'category' => 'professional', 'due_date' => null]);
        Task::factory()->create(['user_id' => $user->id, 'status' => 'in_progress', 'category' => 'personal', 'due_date' => null]);
        Task::factory()->create(['user_id' => $user->id, 'status' => 'todo', 'category' => 'personal', 'due_date' => null]);

        $this->getJson('/api/v1/tasks?category=personal')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('counts.todo', 1)
            ->assertJsonPath('counts.in_progress', 1)
            ->assertJsonPath('counts.done', 0);
    }

    #[Test]
    public function tasks_reject_an_invalid_page(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/tasks?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/tasks?perPage=0')->assertStatus(422)->assertJsonValidationErrors(['perPage']);
        $this->getJson('/api/v1/tasks?perPage=101')
            ->assertStatus(422)
            ->assertJsonPath('errors.perPage.0', 'Cada página pode ter no máximo '.PageResponse::MAX_PER_PAGE.' tarefas.');
    }

    // ---- projetos --------------------------------------------------------

    #[Test]
    public function projects_are_paginated_and_carry_the_total(): void
    {
        $user = $this->actingAsUser();

        Project::factory()->count(3)->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/projects?perPage=2')
            ->assertOk()
            ->assertJson([
                'page' => 1,
                'perPage' => 2,
                'total' => 3,
                'totalPages' => 2,
            ])
            ->assertJsonPath('counts.active', 3)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.progress.total', 0);

        $this->getJson('/api/v1/projects?perPage=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJson(['total' => 3, 'totalPages' => 2]);
    }

    #[Test]
    public function project_counts_ignore_the_status_filter_but_keep_the_others(): void
    {
        $user = $this->actingAsUser();

        Project::factory()->create(['user_id' => $user->id, 'status' => 'active', 'name' => 'Ativo']);
        Project::factory()->paused()->create(['user_id' => $user->id, 'name' => 'Pausado']);
        Project::factory()->done()->create(['user_id' => $user->id, 'name' => 'Concluído']);

        $this->getJson('/api/v1/projects?status=paused')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('counts', ['active' => 1, 'paused' => 1, 'done' => 1])
            ->assertJson(['total' => 1]);
    }

    #[Test]
    public function an_unknown_category_filter_returns_an_empty_page(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/projects?category=inexistente')
            ->assertOk()
            ->assertJson([
                'items' => [],
                'page' => 1,
                'total' => 0,
                'totalPages' => 0,
            ])
            ->assertJsonCount(0, 'items');
    }

    #[Test]
    public function projects_reject_an_invalid_page(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/projects?page=-1')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/projects?perPage=1000')
            ->assertStatus(422)
            ->assertJsonPath('errors.perPage.0', 'Cada página pode ter no máximo '.PageResponse::MAX_PER_PAGE.' projetos.');
    }
}
