<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tasks\ExpireOverdueTasks;
use App\Enums\ActivityType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\RecordActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    // ---- Criação ---------------------------------------------------------

    #[Test]
    public function store_creates_task_with_contract_shape_and_defaults(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/tasks', ['title' => '  Integrar API  '])
            ->assertStatus(201)
            ->assertJson([
                'projectId' => null,
                'title' => 'Integrar API',
                'description' => '',
                'category' => 'professional',
                'urgency' => 'medium',
                'status' => 'todo',
                'canPostpone' => true,
                'dueDate' => null,
                'nextStep' => '',
                'estimateMinutes' => null,
                'tags' => [],
                'isOverdue' => false,
                'postponedCount' => 0,
                'timer' => [
                    'state' => 'idle',
                    'runningSince' => null,
                    'trackedSeconds' => 0,
                    'firstStartedAt' => null,
                ],
                'activity' => [
                    ['type' => 'created', 'message' => 'Tarefa criada'],
                ],
            ])
            ->assertJsonStructure([
                'id', 'timer' => ['serverNow'], 'createdAt', 'updatedAt', 'completedAt',
            ]);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Integrar API',
            'status' => 'todo',
        ]);
    }

    #[Test]
    public function store_accepts_full_payload_with_time_and_resolves_category_and_project(): void
    {
        $user = $this->actingAsUser();

        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/tasks', [
            'title' => 'Preparar exame',
            'description' => 'Matemática discreta',
            'category' => 'PROFESSIONAL',
            'urgency' => 'critical',
            'status' => 'in_progress',
            'canPostpone' => false,
            'dueDate' => now()->addWeek()->format('Y-m-d').'T14:30',
            'nextStep' => 'Comprar o livro',
            'estimateMinutes' => 0,
            'tags' => ['angular', 'api'],
            'projectId' => $project->id,
        ])
            ->assertStatus(201)
            ->assertJson([
                'category' => 'professional',
                'urgency' => 'critical',
                'status' => 'in_progress',
                'canPostpone' => false,
                'dueDate' => now()->addWeek()->format('Y-m-d').'T14:30',
                'estimateMinutes' => 0,
                'tags' => ['angular', 'api'],
                'projectId' => $project->id,
            ]);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Preparar exame',
            'project_id' => $project->id,
            'due_time' => '14:30:00',
        ]);
    }

    #[Test]
    public function store_validates_fields(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/tasks', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title'])
            ->assertJsonPath('errors.title.0', 'O título da tarefa é obrigatório.');

        $this->postJson('/api/v1/tasks', ['title' => 'a'])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Mínimo de 2 caracteres.');

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'urgency' => 'urgente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urgency']);

        // Só todo|in_progress|postponed na criação (doc 07).
        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'status' => 'done'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'status' => 'expired'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'estimateMinutes' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estimateMinutes']);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'tags' => ['a', str_repeat('b', 41)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags.1']);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'tags' => array_fill(0, 21, 'x')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tags']);
    }

    #[Test]
    public function store_rejects_past_due_date_but_accepts_today_and_future(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'dueDate' => now()->subDay()->format('Y-m-d')])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAST_DUE_DATE')
            ->assertJsonPath('errors.dueDate.0', 'Não é possível criar tarefas com prazo no passado.');

        $this->assertDatabaseCount('tasks', 0);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame hoje', 'dueDate' => now()->format('Y-m-d')])
            ->assertStatus(201);

        $this->postJson('/api/v1/tasks', ['title' => 'Exame futuro', 'dueDate' => now()->addWeek()->format('Y-m-d')])
            ->assertStatus(201);

        $this->assertDatabaseCount('tasks', 2);
    }

    #[Test]
    public function store_rejects_invalid_due_date_and_time_without_date(): void
    {
        $this->actingAsUser();

        foreach (['14:30', 'T14:30', '2026-13-01', '2026-02-30', '2026-10-05 25:00'] as $invalid) {
            $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'dueDate' => $invalid])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['dueDate']);
        }

        $this->assertDatabaseCount('tasks', 0);
    }

    #[Test]
    public function store_rejects_invalid_category_and_project_of_another_user(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'category' => 'Inexistente'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $foreignProject = Project::factory()->create(); // outro utilizador

        $this->postJson('/api/v1/tasks', ['title' => 'Exame', 'projectId' => $foreignProject->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['projectId'])
            ->assertJsonPath('errors.projectId.0', 'O projeto indicado não existe.');

        $this->assertDatabaseCount('tasks', 0);
    }

    // ---- Detalhe / isolamento -------------------------------------------

    #[Test]
    public function show_returns_detail_with_activity(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user);

        $this->getJson('/api/v1/tasks/'.$task->id)
            ->assertOk()
            ->assertJson([
                'id' => $task->id,
                'status' => 'todo',
                'activity' => [
                    ['type' => 'created', 'message' => 'Tarefa criada'],
                ],
            ]);
    }

    #[Test]
    public function actions_return_404_for_tasks_of_other_users_and_do_not_modify_them(): void
    {
        $this->actingAsUser();

        $task = $this->makeTask(User::factory()->create(), ['title' => 'De outra conta']);

        $this->getJson('/api/v1/tasks/'.$task->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Não encontrado.');

        $this->patchJson('/api/v1/tasks/'.$task->id, ['title' => 'X Y', 'urgency' => 'critical'])
            ->assertStatus(404);
        $this->deleteJson('/api/v1/tasks/'.$task->id)->assertStatus(404);
        $this->postJson('/api/v1/tasks/'.$task->id.'/complete')->assertStatus(404);
        $this->postJson('/api/v1/tasks/'.$task->id.'/reopen')->assertStatus(404);
        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T09:00'])->assertStatus(404);

        $task->refresh();
        $this->assertSame('De outra conta', $task->title);
        $this->assertSame('medium', $task->urgency->value);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    #[Test]
    public function malformed_task_id_returns_404_instead_of_a_database_error(): void
    {
        $this->actingAsUser();

        foreach (['inexistente', '123', 'abc-def'] as $id) {
            $this->getJson('/api/v1/tasks/'.$id)->assertStatus(404)->assertJsonPath('message', 'Não encontrado.');
            $this->patchJson('/api/v1/tasks/'.$id, ['title' => 'Nome válido'])->assertStatus(404);
            $this->deleteJson('/api/v1/tasks/'.$id)->assertStatus(404);
            $this->postJson('/api/v1/tasks/'.$id.'/complete')->assertStatus(404);
            $this->postJson('/api/v1/tasks/'.$id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T09:00'])->assertStatus(404);
        }
    }

    // ---- Atualização / máquina de estados -------------------------------

    #[Test]
    public function update_changes_only_sent_fields_and_records_activities(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['title' => 'Tarefa', 'next_step' => '']);

        $this->patchJson('/api/v1/tasks/'.$task->id, ['title' => 'Tarefa nova', 'nextStep' => 'Passo novo'])
            ->assertOk()
            ->assertJson([
                'title' => 'Tarefa nova',
                'nextStep' => 'Passo novo',
            ]);

        $types = $task->activity()->get()->pluck('type')->map(fn (ActivityType $t): string => $t->value)->all();
        $this->assertContains('next_step_changed', $types);
        $this->assertContains('edited', $types);
        $this->assertSame(1, $task->activity()->where('type', 'edited')->count());
    }

    #[Test]
    public function patch_status_transitions_and_rejects_invalid_ones(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo']);

        $this->travel(10)->seconds();

        // todo → in_progress válido.
        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson([
                'status' => 'in_progress',
                'activity' => [
                    ['type' => 'status_changed', 'message' => 'Estado alterado para Em curso'],
                    ['type' => 'created', 'message' => 'Tarefa criada'],
                ],
            ]);

        // in_progress → done define completed_at.
        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'done'])->assertOk()->assertJsonPath('status', 'done');
        $this->assertNotNull($task->fresh()->completed_at);

        // done → expired manual é rejeitado (G-07).
        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'expired'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');

        // done → in_progress é inválido.
        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->travelBack();
    }

    #[Test]
    public function patch_postponed_without_due_date_is_rejected_and_with_date_postpones(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo']);

        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'postponed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'POSTPONE_REQUIRES_DATE');

        $future = now()->addDays(3)->format('Y-m-d');

        $this->travel(10)->seconds();

        $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'postponed', 'dueDate' => $future])
            ->assertOk()
            ->assertJson([
                'status' => 'postponed',
                'dueDate' => $future,
                'postponedCount' => 1,
                'activity' => [
                    ['type' => 'postponed', 'message' => 'Adiada para '.Carbon::parse($future)->day.' out 2026'],
                    ['type' => 'created', 'message' => 'Tarefa criada'],
                ],
            ]);

        $this->travelBack();
    }

    #[Test]
    public function update_without_changes_returns_no_changes(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['title' => 'Tarefa', 'status' => 'todo']);

        $this->patchJson('/api/v1/tasks/'.$task->id, [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES');

        $this->patchJson('/api/v1/tasks/'.$task->id, ['title' => 'Tarefa', 'status' => 'todo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES');

        $this->assertSame(1, $task->activity()->count());
    }

    #[Test]
    public function patch_past_due_date_is_allowed_on_update(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo']);

        $past = now()->subDays(2)->format('Y-m-d');

        $this->patchJson('/api/v1/tasks/'.$task->id, ['dueDate' => $past])
            ->assertOk()
            ->assertJsonPath('dueDate', $past);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'due_date' => $past]);
    }

    #[Test]
    public function update_with_an_unchanged_due_date_returns_no_changes(): void
    {
        $user = $this->actingAsUser();
        $due = now()->addWeek()->format('Y-m-d');
        $task = $this->makeTask($user, ['status' => 'todo', 'due_date' => $due, 'due_time' => null]);

        // `dueDate` é um Carbon no model mas string "YYYY-MM-DD" no pedido:
        // reenviar a mesma data não pode contar como mudança (regressão do
        // diff temporal).
        $this->patchJson('/api/v1/tasks/'.$task->id, ['dueDate' => $due])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES');

        $this->assertSame(1, $task->activity()->count());
    }

    #[Test]
    public function patch_reactivates_an_expired_task_when_a_future_due_date_is_set(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'expired', 'due_date' => now()->subDays(5)->format('Y-m-d')]);

        $future = now()->addWeek()->format('Y-m-d');

        $this->travel(10)->seconds();

        // Reactivação implícita (só dueDate).
        $this->patchJson('/api/v1/tasks/'.$task->id, ['dueDate' => $future])
            ->assertOk()
            ->assertJson([
                'status' => 'todo',
                'activity' => [
                    ['type' => 'edited', 'message' => 'Prazo atualizado; tarefa reativada'],
                    ['type' => 'created', 'message' => 'Tarefa criada'],
                ],
            ]);

        $this->travelBack();

        $this->assertSame('todo', $task->fresh()->status->value);

        // Reactivação com status explícito sem data é rejeitada.
        $expired = $this->makeTask($user, ['status' => 'expired', 'due_date' => now()->subDays(5)->format('Y-m-d')]);
        $this->patchJson('/api/v1/tasks/'.$expired->id, ['status' => 'todo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    #[Test]
    public function patch_can_clear_due_date_and_project_association(): void
    {
        $user = $this->actingAsUser();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $task = $this->makeTask($user, ['project_id' => $project->id, 'due_date' => now()->addWeek()->format('Y-m-d')]);

        $this->patchJson('/api/v1/tasks/'.$task->id, ['dueDate' => null, 'projectId' => null])
            ->assertOk()
            ->assertJson(['dueDate' => null, 'projectId' => null]);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => null, 'due_date' => null]);
    }

    // ---- complete --------------------------------------------------------

    #[Test]
    public function complete_marks_done_and_closes_an_open_timer(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $entry = TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
        ]);

        $this->postJson('/api/v1/tasks/'.$task->id.'/complete')
            ->assertOk()
            ->assertJson([
                'task' => ['status' => 'done'],
            ])
            ->assertJsonMissing(['suggestCompleteProject']);

        $this->assertNotNull($task->fresh()->completed_at);

        $entry->refresh();
        $this->assertNotNull($entry->ended_at);
        $this->assertSame('complete', $entry->ended_reason);

        $types = $task->activity()->get()->pluck('type')->map(fn (ActivityType $t): string => $t->value)->all();
        $this->assertContains('status_changed', $types);
        $this->assertContains('timer_stopped', $types);
    }

    #[Test]
    public function complete_suggests_completing_the_project_when_all_tasks_are_done(): void
    {
        $user = $this->actingAsUser();
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd']);

        $a = $this->makeTask($user, ['project_id' => $project->id, 'status' => 'in_progress']);
        $b = $this->makeTask($user, ['project_id' => $project->id, 'status' => 'todo']);

        $this->postJson('/api/v1/tasks/'.$a->id.'/complete')->assertOk()->assertJsonMissing(['suggestCompleteProject']);

        $this->postJson('/api/v1/tasks/'.$b->id.'/complete')
            ->assertOk()
            ->assertJson(['suggestCompleteProject' => ['id' => $project->id, 'name' => 'Loja Nerd']]);
    }

    #[Test]
    public function complete_does_not_suggest_when_an_expired_task_remains(): void
    {
        $user = $this->actingAsUser();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $done = $this->makeTask($user, ['project_id' => $project->id, 'status' => 'in_progress']);
        Task::factory()->expired()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $this->postJson('/api/v1/tasks/'.$done->id.'/complete')
            ->assertOk()
            ->assertJsonMissing(['suggestCompleteProject']);
    }

    #[Test]
    public function completing_an_already_done_task_is_an_invalid_transition(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'done']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/complete')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    #[Test]
    public function an_expired_task_can_be_completed_late(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'expired']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/complete')
            ->assertOk()
            ->assertJsonPath('task.status', 'done');
    }

    // ---- reopen ----------------------------------------------------------

    #[Test]
    public function reopen_brings_a_done_task_back_to_todo(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'done', 'completed_at' => now()]);

        $this->postJson('/api/v1/tasks/'.$task->id.'/reopen')
            ->assertOk()
            ->assertJson(['task' => ['status' => 'todo']]);

        $this->assertNull($task->fresh()->completed_at);
    }

    #[Test]
    public function reopen_rejects_an_open_task(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/reopen')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    // ---- postpone --------------------------------------------------------

    #[Test]
    public function postpone_moves_to_postponed_with_the_right_message(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo']);

        $future = now()->addWeek();

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => $future->format('Y-m-d').'T09:15'])
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'postponed',
                    'postponedCount' => 1,
                    'dueDate' => $future->format('Y-m-d').'T09:15',
                    'activity' => [
                        ['type' => 'postponed', 'message' => "Adiada para {$future->day} out 2026 às 09:15"],
                        ['type' => 'created', 'message' => 'Tarefa criada'],
                    ],
                ],
            ]);

        $this->travelBack();
    }

    #[Test]
    public function postpone_requires_a_date_and_time(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo']);

        // Em falta.
        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dueDate']);

        // Só data (sem hora) é rejeitado — adiar exige data **e** hora.
        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d')])
            ->assertStatus(422)
            ->assertJsonPath('errors.dueDate.0', 'Para adiar, indique a data e a hora (AAAA-MM-DDTHH:mm).');

        // Hora inválida continua a falhar na validação.
        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T25:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dueDate']);
    }

    #[Test]
    public function postpone_enforces_can_postpone_and_future_date(): void
    {
        $user = $this->actingAsUser();

        $noPostpone = $this->makeTask($user, ['status' => 'todo', 'can_postpone' => false]);

        $this->postJson('/api/v1/tasks/'.$noPostpone->id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TASK_CANNOT_POSTPONE');

        $postponable = $this->makeTask($user, ['status' => 'todo', 'can_postpone' => true]);

        $this->postJson('/api/v1/tasks/'.$postponable->id.'/postpone', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dueDate']);

        $this->postJson('/api/v1/tasks/'.$postponable->id.'/postpone', ['dueDate' => now()->subDay()->format('Y-m-d').'T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAST_DUE_DATE');
    }

    #[Test]
    public function postponing_an_already_postponed_task_is_invalid(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'postponed']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T09:00'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    // ---- notas -----------------------------------------------------------

    #[Test]
    public function store_note_creates_a_note_and_returns_the_entry(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user);

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/notes', ['text' => '  Falar com o fornecedor  '])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'at', 'type', 'message'])
            ->assertJson(['type' => 'note', 'message' => 'Falar com o fornecedor']);

        $this->travelBack();

        $this->getJson('/api/v1/tasks/'.$task->id)
            ->assertJsonPath('activity.0.type', 'note');
    }

    #[Test]
    public function store_note_validates_text(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user);

        $this->postJson('/api/v1/tasks/'.$task->id.'/notes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['text']);
    }

    // ---- delete ----------------------------------------------------------

    #[Test]
    public function delete_removes_the_task_with_time_entries_and_activity(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user);

        $entry = TaskTimeEntry::factory()->closed()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
        ]);

        $otherTask = $this->makeTask($user);

        $this->deleteJson('/api/v1/tasks/'.$task->id)
            ->assertStatus(200)
            ->assertJsonPath('message', 'Tarefa removida.');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('task_time_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('activity_entries', ['subject_type' => 'task', 'subject_id' => $task->id]);

        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id]);
    }

    // ---- expiração preguiçosa / tick -------------------------------------

    #[Test]
    public function listing_expires_overdue_tasks_but_keeps_a_running_timer_task_open(): void
    {
        $user = $this->actingAsUser();

        $overdue = $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->subDay()->format('Y-m-d')]);
        $running = $this->makeTask($user, ['status' => 'in_progress', 'due_date' => now()->subDay()->format('Y-m-d')]);

        TaskTimeEntry::factory()->open()->create(['user_id' => $user->id, 'task_id' => $running->id]);

        $this->getJson('/api/v1/tasks?includeExpired=true')->assertOk();

        $this->assertSame('expired', $overdue->fresh()->status->value);
        $this->assertSame('in_progress', $running->fresh()->status->value, 'Tarefa com timer a correr não expira.');
        $this->assertSame(0, $running->activity()->where('type', 'expired')->count(), 'Não regista expiração para tarefa com timer aberto.');
    }

    #[Test]
    public function expiry_respects_the_users_timezone_at_the_midnight_boundary(): void
    {
        // Mesmo instante, tarefas com o mesmo due_date: o utilizador em
        // África/Luanda (UTC+1) já está no dia seguinte, o de UTC não.
        Carbon::setTestNow('2026-10-05T23:30:00');

        $dueDate = '2026-10-05';

        $luanda = User::factory()->withTimezone('Africa/Luanda')->create();
        $utc = User::factory()->withTimezone('UTC')->create();

        $taskLuanda = Task::factory()->withDue($dueDate)->create(['user_id' => $luanda->id, 'status' => 'todo']);
        $taskUtc = Task::factory()->withDue($dueDate)->create(['user_id' => $utc->id, 'status' => 'todo']);

        $expirer = app(ExpireOverdueTasks::class);

        $expirer->forUser($luanda);
        $expirer->forUser($utc);

        $this->assertSame('expired', $taskLuanda->fresh()->status->value);
        $this->assertSame('todo', $taskUtc->fresh()->status->value);
    }

    #[Test]
    public function expiring_an_overdue_task_records_activity(): void
    {
        $user = $this->actingAsUser();
        $task = $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->subDay()->format('Y-m-d')]);

        app(ExpireOverdueTasks::class)->forUser($user);

        $this->assertSame('expired', $task->fresh()->status->value);
        $this->assertSame(1, $task->activity()->where('type', 'expired')->count());
        $this->assertSame(
            'Tarefa expirada por prazo vencido',
            $task->activity()->where('type', 'expired')->first()?->message,
        );
    }

    // ---- filtros ---------------------------------------------------------

    #[Test]
    public function index_excludes_done_and_expired_by_default(): void
    {
        $user = $this->actingAsUser();

        $open = $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->addWeek()->format('Y-m-d')]);
        Task::factory()->done()->create(['user_id' => $user->id, 'due_date' => now()->addWeek()->format('Y-m-d')]);
        Task::factory()->expired()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/tasks')->assertOk();

        $this->assertArrayNotHasKey('activity', $response->json('0'));
        $this->assertSame($open->id, $response->json('0.id'));
    }

    #[Test]
    public function index_can_include_done_and_expired(): void
    {
        $user = $this->actingAsUser();

        $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->addWeek()->format('Y-m-d')]);
        Task::factory()->done()->create(['user_id' => $user->id, 'due_date' => now()->addWeek()->format('Y-m-d')]);
        Task::factory()->expired()->create(['user_id' => $user->id]);

        $this->getJson('/api/v1/tasks?includeDone=true&includeExpired=true')
            ->assertOk()
            ->assertJsonCount(3);
    }

    #[Test]
    public function index_filters_by_category_urgency_status_tag_project_and_search(): void
    {
        $user = $this->actingAsUser();
        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd']);

        $critical = $this->makeTask($user, [
            'category' => 'personal',
            'urgency' => 'critical',
            'status' => 'todo',
            'due_date' => now()->addWeek()->format('Y-m-d'),
        ]);
        $medium = $this->makeTask($user, [
            'category' => 'professional',
            'urgency' => 'medium',
            'status' => 'in_progress',
            'tags' => ['angular'],
            'project_id' => $project->id,
            'due_date' => now()->addDays(4)->format('Y-m-d'),
        ]);
        $loose = $this->makeTask($user, [
            'status' => 'todo',
            'category' => 'household',
            'tags' => ['casa'],
            'due_date' => now()->addWeek()->format('Y-m-d'),
        ]);

        $this->getJson('/api/v1/tasks?urgency=critical')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $critical->id);

        $this->getJson('/api/v1/tasks?status=todo,in_progress')
            ->assertOk()->assertJsonCount(3);

        $this->getJson('/api/v1/tasks?category=Inexistente')
            ->assertOk()->assertJsonCount(0);

        $this->getJson('/api/v1/tasks?category=professional')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $medium->id);

        $this->getJson('/api/v1/tasks?q=loja')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $medium->id);

        $this->getJson('/api/v1/tasks?q=angular')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $medium->id);

        $this->getJson('/api/v1/tasks?projectId=none')
            ->assertOk()->assertJsonCount(2);

        $this->getJson('/api/v1/tasks?projectId='.$project->id)
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $medium->id);

        $this->assertNotNull($loose->id);
    }

    #[Test]
    public function index_filters_by_due_window_and_sort(): void
    {
        $user = $this->actingAsUser();

        $soon = $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->addDay()->format('Y-m-d')]);
        $later = $this->makeTask($user, ['status' => 'todo', 'due_date' => now()->addDays(10)->format('Y-m-d')]);
        $none = $this->makeTask($user, ['status' => 'todo', 'due_date' => null]);

        // Janela dueFrom/dueTo.
        $this->getJson('/api/v1/tasks?dueFrom='.now()->format('Y-m-d').'&dueTo='.now()->addDays(2)->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $soon->id);

        // sort=dueDate: nulos por último.
        $response = $this->getJson('/api/v1/tasks?sort=dueDate')->assertOk();
        $this->assertSame([$soon->id, $later->id, $none->id], $response->json('*.id'));
    }

    #[Test]
    public function index_default_sort_orders_by_urgency(): void
    {
        $user = $this->actingAsUser();

        $low = $this->makeTask($user, ['status' => 'todo', 'urgency' => 'low', 'due_date' => now()->addWeek()->format('Y-m-d')]);
        $critical = $this->makeTask($user, ['status' => 'todo', 'urgency' => 'critical', 'due_date' => now()->addWeek()->format('Y-m-d')]);

        $response = $this->getJson('/api/v1/tasks')->assertOk();

        $this->assertSame([$critical->id, $low->id], $response->json('*.id'));
    }

    #[Test]
    public function index_rejects_invalid_filters(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/tasks?urgency=urgente')->assertStatus(422)->assertJsonValidationErrors(['urgency']);
        $this->getJson('/api/v1/tasks?status=arquivado')->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/tasks?projectId=123')->assertStatus(422)->assertJsonValidationErrors(['projectId']);
        $this->getJson('/api/v1/tasks?dueFrom=2026-13-01')->assertStatus(422)->assertJsonValidationErrors(['dueFrom']);
        $this->getJson('/api/v1/tasks?sort=aleatorio')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    }

    #[Test]
    public function endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/tasks')->assertStatus(401)->assertJsonPath('message', 'Não autenticado.');
        $this->postJson('/api/v1/tasks', ['title' => 'Nova tarefa'])->assertStatus(401);
        $this->getJson('/api/v1/tasks/inexistente')->assertStatus(401);
        $this->postJson('/api/v1/tasks/inexistente/complete')->assertStatus(401);
        $this->postJson('/api/v1/tasks/inexistente/postpone', ['dueDate' => now()->addWeek()->format('Y-m-d').'T09:00'])->assertStatus(401);
    }

    #[Test]
    public function wrong_method_is_reported_as_405(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/tasks'.'/inexistente/complete')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    /**
     * Tarefa do utilizador com a actividade `created` que a action `CreateTask`
     * registaria (as factories não passam pela action).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(User $user, array $attributes = []): Task
    {
        $task = Task::factory()->create($attributes + ['user_id' => $user->id]);

        app(RecordActivity::class)->record(
            $task->user,
            $task,
            ActivityType::Created,
            'Tarefa criada',
        );

        return $task;
    }
}
