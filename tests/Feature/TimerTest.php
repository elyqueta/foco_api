<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\RecordActivity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    // ---- início / retoma ------------------------------------------------

    #[Test]
    public function start_turns_the_task_running_and_sets_first_started_at(): void
    {
        Carbon::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'todo']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'in_progress',
                    'timer' => [
                        'state' => 'running',
                        'runningSince' => '2026-10-11T10:00:00Z',
                        'trackedSeconds' => 0,
                        'firstStartedAt' => '2026-10-11T10:00:00Z',
                    ],
                    'activity' => [
                        ['type' => 'timer_started', 'message' => 'Timer iniciado'],
                        ['type' => 'status_changed', 'message' => 'Estado alterado para Em curso'],
                        ['type' => 'created', 'message' => 'Tarefa criada'],
                    ],
                ],
            ])
            ->assertJsonMissing(['pausedTask']);

        $this->assertDatabaseHas('task_time_entries', [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'ended_at' => null,
            'ended_reason' => null,
        ]);

        $this->assertNotNull($task->fresh()->first_started_at);
        $this->assertSame(1, $task->activity()->where('type', 'timer_started')->count());
    }

    #[Test]
    public function start_requires_idle_and_resume_requires_paused(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'todo']);

        // `resume` numa tarefa sem tempo → estado errado.
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/resume')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_WRONG_STATE');

        // Já a correr → 409 amigável, com a tarefa actual.
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')
            ->assertStatus(409)
            ->assertJsonPath('code', 'TIMER_ALREADY_RUNNING')
            ->assertJsonPath('task.timer.state', 'running')
            ->assertJsonPath('task.timer.trackedSeconds', 10);

        // Pausada: `start` deixa de ser permitido, `resume` sim.
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')
            ->assertOk()
            ->assertJsonPath('task.timer.state', 'paused');

        $this->travelBack();

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_WRONG_STATE');

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/resume')
            ->assertOk()
            ->assertJsonPath('task.timer.state', 'running')
            ->assertJsonPath('task.activity.0.type', 'timer_resumed')
            ->assertJsonPath('task.activity.0.message', 'Timer retomado');

        $this->travelBack();
    }

    #[Test]
    public function resume_on_a_postponed_task_puts_it_back_in_progress(): void
    {
        $user = $this->actingAsUser();

        $due = now()->addWeek()->format('Y-m-d');

        // Tarefa adiada que já tem tempo registado → estado `paused`.
        $task = $this->makeTask($user, [
            'status' => 'postponed',
            'postponed_count' => 1,
            'due_date' => $due,
            'tracked_seconds' => 600,
        ]);

        $this->travel(10)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/resume')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'in_progress',
                    'timer' => ['state' => 'running', 'trackedSeconds' => 600],
                    'activity' => [
                        ['type' => 'timer_resumed', 'message' => 'Timer retomado'],
                        ['type' => 'status_changed', 'message' => 'Estado alterado para Em curso'],
                        ['type' => 'created', 'message' => 'Tarefa criada'],
                    ],
                ],
            ]);

        $this->travelBack();

        // O timer não mexe no prazo da adiada.
        $this->assertSame($due, $task->fresh()->due_date->format('Y-m-d'));
        $this->assertSame('in_progress', $task->fresh()->status->value);
    }

    // ---- pausa ----------------------------------------------------------

    #[Test]
    public function pause_accumulates_seconds_and_keeps_the_task_in_progress(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(90)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'in_progress',
                    'timer' => [
                        'state' => 'paused',
                        'runningSince' => null,
                        'trackedSeconds' => 90,
                    ],
                    'activity' => [
                        ['type' => 'timer_paused', 'message' => 'Timer pausado'],
                    ],
                ],
            ]);

        $this->travelBack();

        $entry = TaskTimeEntry::where('task_id', $task->id)->sole();
        $this->assertSame('pause', $entry->ended_reason);
        $this->assertSame(90, (int) $task->fresh()->tracked_seconds);
    }

    #[Test]
    public function tracked_seconds_includes_the_time_of_the_open_entry(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(45)->seconds();

        // O cliente só soma o que passa desde a resposta: `trackedSeconds` já
        // inclui a entrada a decorrer.
        $this->getJson('/api/v1/tasks/'.$task->id)
            ->assertOk()
            ->assertJson([
                'timer' => ['state' => 'running', 'trackedSeconds' => 45],
            ]);

        $this->travelBack();
    }

    #[Test]
    public function pause_without_a_running_timer_is_rejected(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_NOT_RUNNING');

        $this->assertDatabaseCount('task_time_entries', 0);
    }

    // ---- um timer por utilizador ----------------------------------------

    #[Test]
    public function starting_another_task_pauses_the_running_one_automatically(): void
    {
        $user = $this->actingAsUser();

        $a = $this->makeTask($user, ['status' => 'in_progress', 'title' => 'Tarefa A']);
        $b = $this->makeTask($user, ['status' => 'todo', 'title' => 'Tarefa B']);

        $this->postJson('/api/v1/tasks/'.$a->id.'/timer/start')
            ->assertOk()
            ->assertJsonMissing(['pausedTask']);

        $this->travel(60)->seconds();

        $this->postJson('/api/v1/tasks/'.$b->id.'/timer/start')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'id' => $b->id,
                    'status' => 'in_progress',
                    'timer' => ['state' => 'running', 'trackedSeconds' => 0],
                ],
                'pausedTask' => [
                    'id' => $a->id,
                    'status' => 'in_progress',
                    'timer' => ['state' => 'paused', 'trackedSeconds' => 60],
                ],
            ]);

        $this->travelBack();

        // Uma única entrada aberta para o utilizador.
        $this->assertSame(1, TaskTimeEntry::whereNull('ended_at')->where('user_id', $user->id)->count());

        $this->assertSame(60, (int) $a->fresh()->tracked_seconds);
        $this->assertSame('auto_pause', TaskTimeEntry::where('task_id', $a->id)->sole()->ended_reason);

        $types = $a->activity()->get()->pluck('type')->map(fn (ActivityType $t): string => $t->value)->all();
        $this->assertContains('timer_paused', $types);
        $this->assertSame(
            'Timer pausado automaticamente',
            $a->activity()->where('type', 'timer_paused')->first()?->message,
        );
    }

    #[Test]
    public function only_one_open_entry_per_user_and_per_task_is_enforced(): void
    {
        $user = $this->actingAsUser();

        $a = $this->makeTask($user);
        $b = $this->makeTask($user);

        TaskTimeEntry::factory()->open()->create(['user_id' => $user->id, 'task_id' => $a->id]);

        // Outra tarefa do mesmo utilizador.
        $this->expectException(QueryException::class);
        TaskTimeEntry::factory()->open()->create(['user_id' => $user->id, 'task_id' => $b->id]);
    }

    // ---- elegibilidade --------------------------------------------------

    #[Test]
    public function start_and_resume_are_not_allowed_on_done_or_expired_tasks(): void
    {
        $user = $this->actingAsUser();

        $done = $this->makeTask($user, ['status' => 'done', 'completed_at' => now()]);
        $expired = $this->makeTask($user, ['status' => 'expired', 'due_date' => now()->subDays(3)->format('Y-m-d')]);

        foreach ([$done, $expired] as $task) {
            $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')
                ->assertStatus(422)
                ->assertJsonPath('code', 'TIMER_NOT_ALLOWED');

            $this->postJson('/api/v1/tasks/'.$task->id.'/timer/resume')
                ->assertStatus(422)
                ->assertJsonPath('code', 'TIMER_NOT_ALLOWED');
        }

        $this->assertDatabaseCount('task_time_entries', 0);
    }

    // ---- conclusão / reabertura / adiar ---------------------------------

    #[Test]
    public function complete_closes_the_timer_registers_the_time_and_reopen_keeps_it(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(125)->seconds();

        $this->postJson('/api/v1/tasks/'.$task->id.'/complete')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'done',
                    'timer' => ['state' => 'stopped', 'trackedSeconds' => 125],
                    'activity' => [
                        ['type' => 'timer_stopped', 'message' => 'Tempo registado: 2m'],
                        ['type' => 'status_changed', 'message' => 'Estado alterado para Concluída'],
                        ['type' => 'timer_started', 'message' => 'Timer iniciado'],
                        ['type' => 'created', 'message' => 'Tarefa criada'],
                    ],
                ],
            ]);

        $this->travelBack();

        $this->assertSame('complete', TaskTimeEntry::where('task_id', $task->id)->sole()->ended_reason);

        // Reabrir mantém o tempo acumulado (doc 00 §5.3).
        $this->postJson('/api/v1/tasks/'.$task->id.'/reopen')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'todo',
                    'timer' => ['state' => 'paused', 'trackedSeconds' => 125],
                ],
            ]);
    }

    #[Test]
    public function postpone_and_patch_to_todo_close_the_open_timer(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(60)->seconds();

        $future = now()->addWeek();

        $this->postJson('/api/v1/tasks/'.$task->id.'/postpone', ['dueDate' => $future->format('Y-m-d').'T09:00'])
            ->assertOk()
            ->assertJson([
                'task' => [
                    'status' => 'postponed',
                    'timer' => ['state' => 'paused', 'trackedSeconds' => 60],
                    'activity' => [
                        ['type' => 'timer_stopped', 'message' => 'Tempo registado: 1m'],
                        ['type' => 'postponed', 'message' => "Adiada para {$future->day} out 2026 às 09:00"],
                        ['type' => 'timer_started', 'message' => 'Timer iniciado'],
                        ['type' => 'created', 'message' => 'Tarefa criada'],
                    ],
                ],
            ]);
        $this->travelBack();

        $this->assertSame('postpone', TaskTimeEntry::where('task_id', $task->id)->sole()->ended_reason);

        // `PATCH status → todo` fecha também (motivo `pause`): o timer tinha
        // posto a tarefa `in_progress` e o PATCH volta-a a `todo`.
        $second = $this->makeTask($user, ['status' => 'todo']);

        $this->postJson('/api/v1/tasks/'.$second->id.'/timer/start')->assertOk();

        $this->travel(600)->seconds();

        $this->patchJson('/api/v1/tasks/'.$second->id, ['status' => 'todo'])
            ->assertOk()
            ->assertJsonPath('timer.state', 'paused')
            ->assertJsonPath('timer.trackedSeconds', 600)
            ->assertJsonPath('activity.0.type', 'timer_stopped')
            ->assertJsonPath('activity.0.message', 'Tempo registado: 10m');

        $this->travelBack();

        $entries = TaskTimeEntry::where('task_id', $second->id)->orderBy('started_at')->get();

        $this->assertCount(1, $entries);
        $this->assertSame('pause', $entries->sole()->ended_reason);
        $this->assertSame(600, (int) $second->fresh()->tracked_seconds);
    }

    #[Test]
    public function an_entry_shorter_than_a_second_is_discarded_when_closed(): void
    {
        // Relógio parado: início e pausa no mesmo instante → 0 segundos.
        Carbon::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        // Mesmo segundo: a entrada não chega a 1 s e é descartada (regra 7).
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')
            ->assertOk()
            ->assertJsonPath('task.timer.state', 'idle')
            ->assertJsonPath('task.timer.trackedSeconds', 0);

        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertSame(
            0,
            $task->activity()->where('type', 'timer_paused')->count(),
            'Sem atividade de pausa para um clique acidental.',
        );
    }

    // ---- entradas de tempo ----------------------------------------------

    #[Test]
    public function time_entries_are_listed_most_recent_first_with_seconds(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user);

        TaskTimeEntry::factory()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'ended_reason' => 'pause',
            'created_at' => now()->subHours(3),
        ]);

        TaskTimeEntry::factory()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinutes(30),
            'ended_reason' => 'complete',
            'created_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/tasks/'.$task->id.'/time-entries')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([
                ['id', 'startedAt', 'endedAt', 'endedReason', 'seconds'],
            ])
            ->assertJsonPath('0.seconds', 1800)
            ->assertJsonPath('0.endedReason', 'complete')
            ->assertJsonPath('1.seconds', 3600)
            ->assertJsonPath('1.endedReason', 'pause');
    }

    // ---- timer activo ---------------------------------------------------

    #[Test]
    public function active_timer_returns_the_running_task_or_a_message(): void
    {
        $user = $this->actingAsUser();

        // Sem timer a correr: mensagem PT em vez de um 204 sem corpo.
        $this->getJson('/api/v1/timer/active')
            ->assertOk()
            ->assertJson([
                'task' => null,
                'message' => 'Nenhum timer a correr.',
            ]);

        $a = $this->makeTask($user, ['status' => 'in_progress']);
        $b = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$a->id.'/timer/start')->assertOk();

        $this->getJson('/api/v1/timer/active')
            ->assertOk()
            ->assertJson([
                'task' => [
                    'id' => $a->id,
                    'status' => 'in_progress',
                    'timer' => ['state' => 'running'],
                ],
            ]);

        // A segunda pausa a primeira; `active` passa a ser a B.
        $this->postJson('/api/v1/tasks/'.$b->id.'/timer/start')->assertOk();

        $this->getJson('/api/v1/timer/active')
            ->assertOk()
            ->assertJsonPath('task.id', $b->id);
    }

    // ---- expiração ------------------------------------------------------

    #[Test]
    public function an_overdue_task_expires_after_its_timer_is_paused(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress', 'due_date' => now()->subDay()->format('Y-m-d')]);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        // Com o timer a correr não expira.
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('total', 1);
        $this->assertSame('in_progress', $task->fresh()->status->value);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')->assertOk();

        // Já sem timer, a verificação preguiçosa da listagem expira-a — e a
        // contagem por estado passa a mostrá-la como `expired`.
        $this->getJson('/api/v1/tasks?includeExpired=true')
            ->assertOk()
            ->assertJsonPath('counts.expired', 1);
        $this->assertSame('expired', $task->fresh()->status->value);
    }

    // ---- isolamento e autenticação --------------------------------------

    #[Test]
    public function timer_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/tasks/inexistente/timer/start')->assertStatus(401)->assertJsonPath('message', 'Não autenticado.');
        $this->postJson('/api/v1/tasks/inexistente/timer/pause')->assertStatus(401);
        $this->postJson('/api/v1/tasks/inexistente/timer/resume')->assertStatus(401);
        $this->getJson('/api/v1/tasks/inexistente/time-entries')->assertStatus(401);
        $this->getJson('/api/v1/timer/active')->assertStatus(401);
        $this->getJson('/api/v1/time/summary')->assertStatus(401);
    }

    #[Test]
    public function timer_endpoints_return_404_for_tasks_of_other_users_and_malformed_ids(): void
    {
        $this->actingAsUser();

        $foreign = $this->makeTask(User::factory()->create(), ['title' => 'De outra conta']);

        foreach (['inexistente', '123', 'abc-def', $foreign->id] as $id) {
            $this->postJson('/api/v1/tasks/'.$id.'/timer/start')->assertStatus(404)->assertJsonPath('message', 'Não encontrado.');
            $this->postJson('/api/v1/tasks/'.$id.'/timer/pause')->assertStatus(404);
            $this->postJson('/api/v1/tasks/'.$id.'/timer/resume')->assertStatus(404);
            $this->getJson('/api/v1/tasks/'.$id.'/time-entries')->assertStatus(404);
        }

        $this->assertSame('De outra conta', $foreign->refresh()->title);
    }

    #[Test]
    public function wrong_method_is_reported_as_405(): void
    {
        $this->actingAsUser();

        $task = $this->makeTask($this->actingUser(), ['status' => 'in_progress']);

        $this->getJson('/api/v1/tasks/'.$task->id.'/timer/start')
            ->assertStatus(405)
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    #[Test]
    public function the_running_timer_does_not_leak_between_users(): void
    {
        $owner = $this->actingAsUser();

        $task = $this->makeTask($owner, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        // Segundo utilizador autenticado no mesmo teste (o guarda tem de ser
        // esquecido, senão a stack mantém o primeiro utilizador resolvido).
        $other = $this->switchTo();

        $this->getJson('/api/v1/timer/active')
            ->assertOk()
            ->assertJson(['task' => null, 'message' => 'Nenhum timer a correr.']);

        $mine = $this->makeTask($other, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$mine->id.'/timer/start')
            ->assertOk()
            ->assertJsonPath('task.timer.state', 'running')
            ->assertJsonMissing(['pausedTask']);

        $this->assertSame('in_progress', $task->fresh()->status->value);
        $this->assertSame(1, TaskTimeEntry::whereNull('ended_at')->where('user_id', $owner->id)->count());
        $this->assertSame(2, TaskTimeEntry::whereNull('ended_at')->count());
    }

    #[Test]
    public function the_task_list_does_not_grow_queries_with_the_number_of_running_timers(): void
    {
        $user = $this->actingAsUser();

        $first = $this->makeTask($user, ['status' => 'in_progress', 'due_date' => null]);

        $this->postJson('/api/v1/tasks/'.$first->id.'/timer/start')->assertOk();

        $before = $this->countQueries(fn () => $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'items'));

        // Mais sete tarefas, uma delas com timer a correr.
        for ($i = 0; $i < 6; $i++) {
            $this->makeTask($user, ['status' => 'todo', 'due_date' => null]);
        }

        $running = $this->makeTask($user, ['status' => 'in_progress', 'due_date' => null]);

        $this->postJson('/api/v1/tasks/'.$running->id.'/timer/start')->assertOk();

        $after = $this->countQueries(fn () => $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(8, 'items'));

        // A relação `activeTimeEntry` vem carregada com `with()` — o bloco
        // `timer` de cada tarefa não acrescenta uma query (sem N+1).
        $this->assertSame($before, $after);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        return count(DB::getQueryLog());
    }

    /**
     * Troca de utilizador autenticado dentro do mesmo teste.
     */
    private function switchTo(): User
    {
        $user = $this->actingAsUser();

        $this->app->make('auth')->forgetGuards();

        return $user;
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

    #[Test]
    public function starting_the_timer_on_a_done_task_says_the_task_is_completed(): void
    {
        $user = $this->actingAsUser();

        $done = $this->makeTask($user, ['status' => 'done', 'completed_at' => now()]);

        $this->postJson('/api/v1/tasks/'.$done->id.'/timer/start')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_NOT_ALLOWED')
            ->assertJsonPath('message', 'Não é possível iniciar o tempo de uma tarefa concluída.');
    }

    #[Test]
    public function starting_the_timer_on_an_expired_task_says_how_to_reactivate_it(): void
    {
        $user = $this->actingAsUser();

        $expired = $this->makeTask($user, [
            'status' => 'expired',
            'due_date' => now()->subDays(3)->format('Y-m-d'),
        ]);

        $this->postJson('/api/v1/tasks/'.$expired->id.'/timer/start')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_NOT_ALLOWED')
            ->assertJsonPath('message', 'Não é possível iniciar o tempo de uma tarefa expirada. Reative-a com um novo prazo.');
    }

    #[Test]
    public function pausing_twice_does_not_count_the_same_time_twice(): void
    {
        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/start')->assertOk();

        $this->travel(90)->seconds();

        // Duplo clique: os dois pedidos chegam ao mesmo tempo.
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')->assertOk();
        $this->postJson('/api/v1/tasks/'.$task->id.'/timer/pause')
            ->assertStatus(422)
            ->assertJsonPath('code', 'TIMER_NOT_RUNNING');

        $this->travelBack();

        $this->assertSame(90, (int) $task->fresh()->tracked_seconds);
        $this->assertSame(1, TaskTimeEntry::whereNotNull('ended_at')->count());
    }
}
