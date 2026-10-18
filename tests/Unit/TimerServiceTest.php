<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TimerState;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\TimerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TimerServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimerService $timers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timers = new TimerService;
    }

    #[Test]
    public function it_formats_durations_in_portuguese(): void
    {
        // Formato do contrato: `Xh Ym` (minutos inteiros; abaixo de um minuto
        // não há unidade — as entradas com < 1 s nem são guardadas).
        $this->assertSame('0m', $this->timers->format(0));
        $this->assertSame('0m', $this->timers->format(59));
        $this->assertSame('1m', $this->timers->format(60));
        $this->assertSame('30m', $this->timers->format(1800));
        $this->assertSame('1h 0m', $this->timers->format(3600));
        $this->assertSame('1h 1m', $this->timers->format(3660));
        $this->assertSame('16h 0m', $this->timers->format(57600));
        $this->assertSame('0m', $this->timers->format(-10));
    }

    #[Test]
    public function it_computes_the_state_of_the_timer(): void
    {
        CarbonImmutable::setTestNow('2026-10-10T12:00:00');

        $user = User::factory()->create();

        $task = Task::factory()->create(['user_id' => $user->id, 'tracked_seconds' => 300]);

        // Tempo registado e tarefa aberta → paused.
        $this->assertSame(TimerState::Paused, $this->timers->state($task));

        // Entrada aberta → running (modelo relido: a relação é em cache).
        $entry = $this->openEntry($task, 300);

        $this->assertSame(TimerState::Running, $this->timers->state($task->fresh()));

        // Entrada fechada com tempo acumulado → paused outra vez.
        $entry->update(['ended_at' => CarbonImmutable::parse('2026-10-10T10:05:00')]);

        $this->assertSame(TimerState::Paused, $this->timers->state($task->fresh()));

        // Concluída com tempo → stopped.
        $task->update(['status' => 'done']);

        $this->assertSame(TimerState::Stopped, $this->timers->state($task->fresh()));
    }

    #[Test]
    public function an_open_task_without_time_or_entries_is_idle(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->create(['user_id' => $user->id, 'tracked_seconds' => 0]);

        $this->assertSame(TimerState::Idle, $this->timers->state($task));
    }

    #[Test]
    public function a_done_task_without_tracked_time_is_also_idle(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->done()->create(['user_id' => $user->id, 'tracked_seconds' => 0]);

        $this->assertSame(TimerState::Idle, $this->timers->state($task));
    }

    #[Test]
    public function it_adds_the_open_entry_to_the_tracked_seconds(): void
    {
        CarbonImmutable::setTestNow('2026-10-10T12:00:00');

        $user = User::factory()->create();

        $task = Task::factory()->create(['user_id' => $user->id, 'tracked_seconds' => 600]);

        $this->openEntry($task, 0);

        // Sem `now` explícito usa a hora do servidor (agora).
        $this->assertSame(600, $this->timers->trackedSeconds($task));

        // 600 acumulados + 3600 da entrada aberta até à hora pedida.
        $this->assertSame(4200, $this->timers->trackedSeconds($task, CarbonImmutable::parse('2026-10-10T13:00:00')));
        $this->assertSame(600, $this->timers->secondsOf(
            TaskTimeEntry::where('task_id', $task->id)->sole(),
            CarbonImmutable::parse('2026-10-10T12:10:00'),
        ));
    }

    #[Test]
    public function it_never_returns_negative_seconds(): void
    {
        $entry = new TaskTimeEntry([
            'started_at' => CarbonImmutable::parse('2026-10-10T12:00:10'),
            'ended_at' => CarbonImmutable::parse('2026-10-10T12:00:00'),
        ]);

        $this->assertSame(0, $this->timers->secondsOf($entry));
    }

    #[Test]
    public function the_open_entry_is_read_from_the_loaded_relation(): void
    {
        CarbonImmutable::setTestNow('2026-10-10T12:00:00');

        $user = User::factory()->create();

        $task = Task::factory()->create(['user_id' => $user->id, 'tracked_seconds' => 0]);

        $entry = $this->openEntry($task, 60);

        // Relação carregada: nenhuma query extra (o `assertDatabaseCount` da
        // feature confirma que a lista não faz N+1).
        $task->setRelation('activeTimeEntry', $entry);
        $task->load('activeTimeEntry');

        $this->assertSame($entry->id, $this->timers->open($task)?->id);
    }

    private function openEntry(Task $task, int $minutesAgo): TaskTimeEntry
    {
        $started = CarbonImmutable::now()->subMinutes($minutesAgo);

        return TaskTimeEntry::create([
            'user_id' => $task->user_id,
            'task_id' => $task->id,
            'started_at' => $started,
            'created_at' => $started,
        ]);
    }
}
