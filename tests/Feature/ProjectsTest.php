<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\RecordActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function index_lists_projects_without_activity_and_with_progress(): void
    {
        $user = $this->actingAsUser();

        $newest = $this->makeProject($user, [
            'name' => 'Novo',
            'created_at' => now(),
        ]);

        $oldest = $this->makeProject($user, [
            'name' => 'Antigo',
            'created_at' => now()->subDay(),
        ]);

        Task::factory()->done()->create(['user_id' => $user->id, 'project_id' => $newest->id]);
        Task::factory()->create(['user_id' => $user->id, 'project_id' => $newest->id]);

        $response = $this->getJson('/api/v1/projects')->assertOk();

        $response->assertJsonCount(2);

        $projects = collect($response->json());

        // Mais recente primeiro; a lista não carrega `activity` (T-08).
        $this->assertSame([$newest->id, $oldest->id], $projects->pluck('id')->all());
        $this->assertArrayNotHasKey('activity', $projects[0]);

        $this->assertSame([
            'total' => 2,
            'done' => 1,
            'percent' => 50,
        ], $projects->firstWhere('id', $newest->id)['progress']);
    }

    #[Test]
    public function index_filters_by_category_status_and_search(): void
    {
        $user = $this->actingAsUser();

        $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $estudos = $this->makeProject($user, [
            'name' => 'Preparar exame',
            'description' => 'Matemática discreta',
            'category' => 'Estudos',
        ]);
        $pausado = $this->makeProject($user, [
            'name' => 'Loja Nerd',
            'description' => 'Marca de acessórios',
            'status' => 'paused',
        ]);
        $this->makeProject($user, [
            'name' => 'Destino Mussulo',
            'description' => 'Plataforma de reservas',
        ]);

        $this->getJson('/api/v1/projects?category=estudos')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $estudos->id);

        $this->getJson('/api/v1/projects?category=Inexistente')
            ->assertOk()
            ->assertJsonCount(0);

        $this->getJson('/api/v1/projects?status=paused')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pausado->id);

        $this->getJson('/api/v1/projects?q=loja')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $pausado->id);

        $this->getJson('/api/v1/projects?q=reservas')
            ->assertOk()
            ->assertJsonCount(1);
    }

    #[Test]
    public function index_rejects_invalid_status_filter(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/projects?status=arquivado')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status'])
            ->assertJsonPath('errors.status.0', 'O estado indicado não é válido.');
    }

    #[Test]
    public function store_creates_project_with_contract_shape_and_defaults(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/projects', ['name' => '  Loja Nerd  '])
            ->assertStatus(201)
            ->assertJson([
                'name' => 'Loja Nerd',
                'description' => '',
                'category' => 'professional',
                'urgency' => 'medium',
                'status' => 'active',
                'canPostpone' => true,
                'dueDate' => null,
                'nextStep' => '',
                'color' => '#6C5CE7',
                'progress' => ['total' => 0, 'done' => 0, 'percent' => 0],
                'activity' => [
                    ['type' => 'created', 'message' => 'Projeto criado'],
                ],
            ])
            ->assertJsonStructure([
                'id',
                'createdAt',
                'updatedAt',
            ]);

        $project = Project::firstWhere('name', 'Loja Nerd');

        $this->assertNotNull($project);
        $this->assertSame($user->id, $project->user_id);
    }

    #[Test]
    public function store_accepts_full_payload_and_resolves_category_name(): void
    {
        $user = $this->actingAsUser();

        $user->categories()->create(['name' => 'Estudos', 'name_key' => 'estudos', 'is_default' => false]);

        $this->postJson('/api/v1/projects', [
            'name' => 'Preparar exame',
            'description' => 'Matemática',
            'category' => 'estudos',
            'urgency' => 'critical',
            'status' => 'paused',
            'canPostpone' => false,
            'dueDate' => '2026-12-01T18:30',
            'nextStep' => 'Comprar o livro',
            'color' => '#3FBF9A',
        ])
            ->assertStatus(201)
            ->assertJson([
                'category' => 'Estudos',
                'urgency' => 'critical',
                'status' => 'paused',
                'canPostpone' => false,
                'dueDate' => '2026-12-01',
                'nextStep' => 'Comprar o livro',
                'color' => '#3FBF9A',
            ]);

        $this->assertDatabaseHas('projects', [
            'name' => 'Preparar exame',
            'category' => 'Estudos',
            'due_date' => '2026-12-01',
            'color' => '#3FBF9A',
        ]);
    }

    #[Test]
    public function store_validates_fields(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/projects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'O nome do projeto é obrigatório.');

        $this->postJson('/api/v1/projects', ['name' => 'a'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Mínimo de 2 caracteres.');

        $this->postJson('/api/v1/projects', ['name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Máximo de 255 caracteres.');

        $this->postJson('/api/v1/projects', ['name' => 'Exame', 'urgency' => 'urgente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urgency'])
            ->assertJsonPath('errors.urgency.0', 'A urgência indicada não é válida.');

        $this->postJson('/api/v1/projects', ['name' => 'Exame', 'status' => 'arquivado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/projects', ['name' => 'Exame', 'dueDate' => 'não é data'])
            ->assertStatus(422)
            ->assertJsonPath('errors.dueDate.0', 'A data de prazo não é válida.');

        $this->postJson('/api/v1/projects', ['name' => 'Exame', 'color' => 'azul'])
            ->assertStatus(422)
            ->assertJsonPath('errors.color.0', 'A cor deve estar no formato #RRGGBB (ex.: #6C5CE7).');
    }

    #[Test]
    public function store_rejects_invalid_category(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/projects', ['name' => 'Exame', 'category' => 'Inexistente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category'])
            ->assertJsonPath('errors.category.0', 'A categoria indicada não existe.');
    }

    #[Test]
    public function show_returns_detail_with_activity_and_progress(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        Task::factory()->count(4)->create(['user_id' => $user->id, 'project_id' => $project->id]);
        Task::factory()->done()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJson([
                'id' => $project->id,
                'progress' => ['total' => 5, 'done' => 1, 'percent' => 20],
                'activity' => [
                    ['type' => 'created', 'message' => 'Projeto criado'],
                ],
            ]);
    }

    #[Test]
    public function show_and_actions_return_404_for_projects_of_other_users(): void
    {
        $this->actingAsUser();

        $project = $this->makeProject(User::factory()->create(), ['name' => 'De outra conta']);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Não encontrado.');

        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'Nome válido'])
            ->assertStatus(404);

        $this->deleteJson('/api/v1/projects/'.$project->id)->assertStatus(404);
        $this->postJson('/api/v1/projects/'.$project->id.'/notes', ['text' => 'nota'])->assertStatus(404);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertSame('De outra conta', $project->fresh()?->name);
    }

    #[Test]
    public function update_changes_only_the_sent_fields(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, [
            'name' => 'Loja Nerd',
            'description' => 'Marca de acessórios',
            'category' => 'personal',
            'urgency' => 'high',
            'color' => '#3FBF9A',
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'Loja Nerd Tech'])
            ->assertOk()
            ->assertJson([
                'name' => 'Loja Nerd Tech',
                'description' => 'Marca de acessórios',
                'category' => 'personal',
                'urgency' => 'high',
                'color' => '#3FBF9A',
                'activity' => [
                    ['type' => 'edited', 'message' => 'Projeto editado'],
                ],
            ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Loja Nerd Tech',
            'description' => 'Marca de acessórios',
        ]);
    }

    #[Test]
    public function update_records_status_changed_activity(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, ['status' => 'active']);

        $this->patchJson('/api/v1/projects/'.$project->id, ['status' => 'paused'])
            ->assertOk()
            ->assertJson([
                'status' => 'paused',
                'activity' => [
                    ['type' => 'status_changed', 'message' => 'Estado alterado para Pausado'],
                ],
            ]);

        $this->assertDatabaseMissing('activity_entries', [
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'type' => 'edited',
        ]);
    }

    #[Test]
    public function update_records_next_step_changed_activity(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, ['next_step' => 'Passo antigo']);

        $this->patchJson('/api/v1/projects/'.$project->id, ['nextStep' => 'Definir catálogo'])
            ->assertOk()
            ->assertJson([
                'nextStep' => 'Definir catálogo',
                'activity' => [
                    ['type' => 'next_step_changed', 'message' => 'Próximo passo atualizado'],
                ],
            ]);
    }

    #[Test]
    public function update_records_a_single_edited_entry_for_other_fields(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, [
            'name' => 'Loja Nerd',
            'description' => 'Antiga',
            'color' => '#6C5CE7',
            'next_step' => 'Passo',
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, [
            'name' => 'Loja Nerd Tech',
            'description' => 'Nova descrição',
            'color' => '#3FBF9A',
            'urgency' => 'low',
        ])->assertOk();

        $this->assertSame(2, $project->activity()->count(), 'Criada + uma única edição.');
        $this->assertSame(1, $project->activity()->where('type', 'edited')->count());
        $this->assertSame(0, $project->activity()->where('type', 'next_step_changed')->count());
    }

    #[Test]
    public function update_combines_status_next_step_and_edited_in_one_request(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, [
            'status' => 'active',
            'next_step' => 'Antigo',
            'name' => 'Loja Nerd',
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, [
            'name' => 'Loja Nerd Tech',
            'status' => 'done',
            'nextStep' => 'Novo passo',
        ])->assertOk();

        $types = $project->activity()->get()->pluck('type')
            ->map(fn (ActivityType $type): string => $type->value)
            ->all();

        $this->assertContains('status_changed', $types);
        $this->assertContains('next_step_changed', $types);
        $this->assertContains('edited', $types);
    }

    #[Test]
    public function update_without_changes_returns_no_changes(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, [
            'name' => 'Loja Nerd',
            'description' => 'Marca de acessórios',
            'next_step' => 'Passo',
            'can_postpone' => true,
            'status' => 'active',
            'urgency' => 'medium',
            'category' => 'professional',
            'color' => '#6C5CE7',
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES')
            ->assertJsonPath('message', 'Nenhuma alteração detetada.');

        $this->patchJson('/api/v1/projects/'.$project->id, [
            'name' => 'Loja Nerd',
            'description' => 'Marca de acessórios',
            'nextStep' => 'Passo',
            'canPostpone' => true,
            'status' => 'active',
            'urgency' => 'medium',
            'category' => 'professional',
            'color' => '#6C5CE7',
            'dueDate' => $project->due_date?->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonPath('code', 'NO_CHANGES');

        $this->assertSame(1, $project->activity()->count(), 'Só a atividade de criação.');
    }

    #[Test]
    public function update_clears_due_date_with_null(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user, [
            'due_date' => now()->addWeek()->format('Y-m-d'),
        ]);

        $this->patchJson('/api/v1/projects/'.$project->id, ['dueDate' => null])
            ->assertOk()
            ->assertJsonPath('dueDate', null);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'due_date' => null]);
    }

    #[Test]
    public function update_keeps_due_date_when_the_same_value_is_sent(): void
    {
        $user = $this->actingAsUser();

        $due = now()->addWeek()->format('Y-m-d');

        $project = $this->makeProject($user, ['due_date' => $due]);

        $this->patchJson('/api/v1/projects/'.$project->id, ['dueDate' => $due])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES');
    }

    #[Test]
    public function update_rejects_invalid_category(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        $this->patchJson('/api/v1/projects/'.$project->id, ['category' => 'Inexistente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category'])
            ->assertJsonPath('errors.category.0', 'A categoria indicada não existe.');
    }

    #[Test]
    public function delete_removes_project_tasks_time_entries_and_activity(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        $task = Task::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);
        $timeEntry = TaskTimeEntry::factory()->closed()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
        ]);

        app(RecordActivity::class)->record($user, $task, ActivityType::Created, 'Tarefa criada');

        // Tarefa noutro projeto do mesmo utilizador: não pode ser afetada.
        $otherProject = $this->makeProject($user);
        $otherTask = Task::factory()->create(['user_id' => $user->id, 'project_id' => $otherProject->id]);
        app(RecordActivity::class)->record($user, $otherTask, ActivityType::Created, 'Tarefa criada');

        $this->deleteJson('/api/v1/projects/'.$project->id)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Projeto removido.');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('task_time_entries', ['id' => $timeEntry->id]);
        $this->assertDatabaseMissing('activity_entries', [
            'subject_type' => 'project',
            'subject_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('activity_entries', [
            'subject_type' => 'task',
            'subject_id' => $task->id,
        ]);

        $this->assertDatabaseHas('projects', ['id' => $otherProject->id]);
        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id]);
        $this->assertDatabaseHas('activity_entries', [
            'subject_type' => 'task',
            'subject_id' => $otherTask->id,
        ]);
    }

    #[Test]
    public function store_note_creates_note_activity_and_returns_the_entry(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/projects/'.$project->id.'/notes', ['text' => '  Falar com o fornecedor  '])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'at', 'type', 'message'])
            ->assertJson([
                'type' => 'note',
                'message' => 'Falar com o fornecedor',
            ]);

        $this->travelBack();

        $activity = collect($this->getJson('/api/v1/projects/'.$project->id)->assertOk()->json('activity'));

        $this->assertSame('note', $activity->first()['type'], 'Atividade mais recente primeiro.');
        $this->assertSame('Falar com o fornecedor', $activity->first()['message']);
        $this->assertSame('created', $activity->last()['type']);
    }

    #[Test]
    public function store_note_validates_text(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        $this->postJson('/api/v1/projects/'.$project->id.'/notes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['text'])
            ->assertJsonPath('errors.text.0', 'O texto da nota é obrigatório.');

        $this->postJson('/api/v1/projects/'.$project->id.'/notes', ['text' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('errors.text.0', 'Máximo de 2000 caracteres.');
    }

    #[Test]
    public function progress_counts_expired_tasks_in_the_total(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        Task::factory()->done()->create(['user_id' => $user->id, 'project_id' => $project->id]);
        Task::factory()->expired()->create(['user_id' => $user->id, 'project_id' => $project->id]);
        Task::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);
        Task::factory()->postponed()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('progress', ['total' => 4, 'done' => 1, 'percent' => 25]);
    }

    #[Test]
    public function progress_rounds_the_percentage(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        Task::factory()->count(3)->create(['user_id' => $user->id, 'project_id' => $project->id]);
        Task::factory()->done()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('0.progress', ['total' => 4, 'done' => 1, 'percent' => 25]);
    }

    #[Test]
    public function endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/projects')->assertStatus(401)->assertJsonPath('message', 'Não autenticado.');
        $this->postJson('/api/v1/projects', ['name' => 'Loja Nerd'])->assertStatus(401);
        $this->getJson('/api/v1/projects/inexistente')->assertStatus(401);
        $this->patchJson('/api/v1/projects/inexistente', ['name' => 'X Y'])->assertStatus(401);
        $this->deleteJson('/api/v1/projects/inexistente')->assertStatus(401);
        $this->postJson('/api/v1/projects/inexistente/notes', ['text' => 'nota'])->assertStatus(401);
    }

    #[Test]
    public function a_malformed_project_id_returns_404_instead_of_a_database_error(): void
    {
        $this->actingAsUser();

        // Em PostgreSQL a coluna é `uuid`: sem a validação do formato a query
        // falharia com erro de sintaxe e a resposta seria 503.
        foreach (['inexistente', '123', 'abc-def'] as $id) {
            $this->getJson('/api/v1/projects/'.$id)
                ->assertStatus(404)
                ->assertJsonPath('message', 'Não encontrado.');

            $this->patchJson('/api/v1/projects/'.$id, ['name' => 'Nome válido'])->assertStatus(404);
            $this->deleteJson('/api/v1/projects/'.$id)->assertStatus(404);
            $this->postJson('/api/v1/projects/'.$id.'/notes', ['text' => 'nota'])->assertStatus(404);
        }
    }

    #[Test]
    public function due_date_accepts_only_a_real_date(): void
    {
        $this->actingAsUser();

        // A regra `date` do Laravel aceita estes valores, mas o PostgreSQL
        // rejeita-os ao escrever na coluna `date`.
        foreach (['2026-10', '12 September 2026', '2026-13-01', '2026-02-30', 'ontem', '20-10-2026'] as $invalid) {
            $response = $this->postJson('/api/v1/projects', ['name' => 'Exame', 'dueDate' => $invalid]);

            $response->assertStatus(422)->assertJsonPath('errors.dueDate.0', 'A data de prazo não é válida.');
        }

        $this->assertDatabaseCount('projects', 0);
    }

    #[Test]
    public function due_date_is_truncated_to_the_date_part(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        $this->patchJson('/api/v1/projects/'.$project->id, ['dueDate' => '2026-12-01T18:30:00'])
            ->assertOk()
            ->assertJsonPath('dueDate', '2026-12-01');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'due_date' => '2026-12-01']);
    }

    #[Test]
    public function string_fields_are_trimmed_before_validation(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/projects', [
            'name' => ' Exame ',
            'urgency' => ' critical ',
            'status' => ' paused ',
            'dueDate' => ' 2026-12-01 ',
        ])
            ->assertStatus(201)
            ->assertJson([
                'name' => 'Exame',
                'urgency' => 'critical',
                'status' => 'paused',
                'dueDate' => '2026-12-01',
            ]);
    }

    #[Test]
    public function detail_returns_a_capped_activity_history(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        foreach (range(1, 149) as $i) {
            app(RecordActivity::class)->record(
                $user,
                $project,
                ActivityType::Note,
                'Nota '.$i,
                now()->addSeconds($i),
            );
        }

        $activity = $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->json('activity');

        // 150 entradas no total (149 notas + a criação); só as 100 mais
        // recentes são devolvidas, da mais recente para a mais antiga.
        $this->assertCount(100, $activity);
        $this->assertSame('Nota 149', $activity[0]['message']);
    }

    #[Test]
    public function delete_removes_tasks_time_entries_and_activity_through_database_cascade(): void
    {
        $user = $this->actingAsUser();

        $project = $this->makeProject($user);

        // Várias tarefas cobrem os caminhos de subquery e de cascade.
        $tasks = Task::factory()
            ->count(3)
            ->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $timeEntries = $tasks->map(fn (Task $task) => TaskTimeEntry::factory()->closed()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
        ]));

        foreach ($tasks as $task) {
            app(RecordActivity::class)->record($user, $task, ActivityType::Created, 'Tarefa criada');
        }

        $this->deleteJson('/api/v1/projects/'.$project->id)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Projeto removido.');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertDatabaseCount('activity_entries', 0);
    }

    #[Test]
    public function method_not_allowed_is_reported_with_allowed_methods(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/projects', ['name' => 'Loja Nerd'])
            ->assertStatus(201);

        $project = Project::firstWhere('name', 'Loja Nerd');

        // GET não existe em /notes (só POST).
        $this->getJson('/api/v1/projects/'.$project?->id.'/notes')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    /**
     * Projeto do utilizador com a atividade `created` que a ação
     * `CreateProject` registaria (as factories não passam pela ação).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(User $user, array $attributes = []): Project
    {
        $project = Project::factory()->create($attributes + ['user_id' => $user->id]);

        app(RecordActivity::class)->record(
            $project->user,
            $project,
            ActivityType::Created,
            'Projeto criado',
        );

        return $project;
    }
}
