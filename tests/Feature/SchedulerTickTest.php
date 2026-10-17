<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationDispatchLog;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\CloseStaleTimers;
use App\Services\TickService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ActsAsUser;
use Tests\TestCase;

class SchedulerTickTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    private const SECRET = 'segredo-do-cron-de-teste';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cron.secret' => self::SECRET]);
    }

    private function tick(array $headers = []): TestResponse
    {
        return $this->postJson('/api/internal/scheduler/tick', [], $headers);
    }

    #[Test]
    public function a_tick_without_the_secret_is_rejected(): void
    {
        $this->postJson('/api/internal/scheduler/tick')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CRON_SECRET');
    }

    #[Test]
    public function a_tick_with_the_wrong_secret_is_rejected(): void
    {
        $this->tick(['X-Cron-Secret' => 'outro-segredo'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CRON_SECRET');
    }

    #[Test]
    public function a_tick_without_a_configured_secret_answers_503(): void
    {
        config(['services.cron.secret' => null]);

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'CRON_NOT_CONFIGURED');
    }

    #[Test]
    public function the_tick_also_answers_get(): void
    {
        $response = $this->getJson('/api/internal/scheduler/tick', ['X-Cron-Secret' => self::SECRET]);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function a_tick_expires_overdue_tasks_and_notifies_once(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00'); // 11:00 em Luanda

        $user = $this->actingAsUser();

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'title' => 'Enviar relatório',
            'status' => 'todo',
            'due_date' => '2026-10-10',
        ]);

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('expired', 1);

        $this->assertSame('expired', $task->fresh()->status->value);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('task_expired', $user->notifications()->first()->type);

        // Um segundo tick não duplica nada (a tarefa já não corresponde à
        // query e a notificação tem dedupe).
        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('expired', 0);

        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function a_task_with_an_open_timer_does_not_expire(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        // digest fora da hora: este teste é só da expiração.
        $user->notificationPreference->update(['digest_hour' => 20]);

        $task = Task::factory()->inProgress()->create([
            'user_id' => $user->id,
            'due_date' => '2026-10-10',
        ]);

        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => '2026-10-11T09:00:00',
        ]);

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('expired', 0);

        $this->assertSame('in_progress', $task->fresh()->status->value);
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function the_summary_counts_what_the_tick_sent_and_skipped(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        // Digest fora da hora: este teste é só dos lembretes.
        $user->notificationPreference->update(['digest_hour' => 20]);

        Task::factory()->withDue('2026-10-11', '15:00')->create([
            'user_id' => $user->id,
            'title' => 'Enviar relatório',
            'status' => 'todo',
        ]);

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('sent.in_app', 1)
            ->assertJsonPath('sent.email', 1)
            ->assertJsonPath('failed', 0);

        $this->assertSame(1, $user->notifications()->count());

        $log = NotificationDispatchLog::firstWhere('channel', 'email');

        $this->assertNotNull($log);
        $this->assertSame('task_due_soon', $log->type);
        $this->assertSame('sent', $log->status);
    }

    #[Test]
    public function a_tick_that_is_already_running_is_not_repeated(): void
    {
        $this->actingAsUser();

        // Simula um tick a correr (o lock fica preso até ser largado).
        Cache::lock(TickService::LOCK_KEY, TickService::LOCK_SECONDS)->get();

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertStatus(202)
            ->assertJson(['ok' => true, 'skipped' => 'locked']);
    }

    #[Test]
    public function the_tick_stops_processing_new_items_when_the_time_budget_runs_out(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $this->actingAsUser();
        $this->actingAsUser();

        config(['services.scheduler.time_budget' => 0]);

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('timeBudgetExceeded', true);
    }

    #[Test]
    public function a_failing_step_does_not_stop_the_tick(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        // A expiração continua a expirar mesmo que um passo falhe: o resumo
        // conta a falha e o pedido responde 200.
        $this->mock(CloseStaleTimers::class, function ($mock): void {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('Base de dados em baixo.'));
        });

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'todo',
            'due_date' => '2026-10-10',
        ]);

        $this->tick(['X-Cron-Secret' => self::SECRET])->assertOk();

        $this->assertSame('expired', $task->fresh()->status->value);
    }

    #[Test]
    public function the_tick_does_not_touch_users_without_anything_to_report(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        $this->tick(['X-Cron-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('sent.in_app', 0)
            ->assertJsonPath('sent.email', 0);

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, NotificationDispatchLog::count());
    }
}
