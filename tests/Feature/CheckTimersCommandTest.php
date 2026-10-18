<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationDispatchLog;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class CheckTimersCommandTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function it_closes_entries_open_for_more_than_16_hours_and_records_activity(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['status' => 'in_progress']);

        $stale = TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => '2026-10-09T16:00:00', // 20 h aberta
            'created_at' => '2026-10-09T16:00:00',
        ]);

        // Outro utilizador com um timer recente: não pode ser tocado.
        $other = $this->switchTo();

        $fresh = TaskTimeEntry::factory()->open()->create([
            'user_id' => $other->id,
            'task_id' => $this->makeTask($other, ['status' => 'in_progress'])->id,
            'started_at' => '2026-10-10T10:00:00', // 2 h aberta
            'created_at' => '2026-10-10T10:00:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();

        $stale->refresh();
        $this->assertNotNull($stale->ended_at);
        $this->assertSame('auto_pause', $stale->ended_reason);
        $this->assertEquals(72000, $stale->started_at->diffInSeconds($stale->ended_at));

        // O tempo fica acumulado na tarefa (nada é perdido).
        $this->assertSame(72000, (int) $task->fresh()->tracked_seconds);

        $this->assertSame(
            'Timer pausado automaticamente (inatividade)',
            $task->activity()->where('type', 'timer_paused')->first()?->message,
        );

        // Recentes e de outros utilizadores ficam a correr.
        $fresh->refresh();
        $this->assertNull($fresh->ended_at);
    }

    #[Test]
    public function it_notifies_once_per_entry_for_long_timers(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $task = $this->makeTask($user, ['title' => 'Integrar API']);

        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => '2026-10-10T06:30:00', // 5 h 30 (≥ 4 h por omissão)
            'created_at' => '2026-10-10T06:30:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();

        $this->assertSame(1, $user->notifications()->count());

        $notification = $user->notifications()->first();

        $this->assertSame('timer_running_long', $notification->type);
        $this->assertSame('Timer ainda a correr', $notification->data['title']);
        $this->assertSame(
            'O tempo de «Integrar API» está a correr há 5h 30m. Queres pausar?',
            $notification->data['body'],
        );
        $this->assertSame($task->id, $notification->data['taskId']);

        // Idempotente: uma notificação por entrada.
        $this->artisan('foco:check-timers')->assertSuccessful();
        $this->assertSame(1, $user->notifications()->count());

        $this->assertSame(1, NotificationDispatchLog::count());
    }

    #[Test]
    public function it_does_not_notify_short_timers_or_when_in_app_is_disabled(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        // 1 h aberta: abaixo das 4 h por omissão.
        $short = TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $this->makeTask($user)->id,
            'started_at' => '2026-10-10T11:00:00',
            'created_at' => '2026-10-10T11:00:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();
        $this->assertSame(0, $user->notifications()->count());

        // Só pode haver uma entrada aberta por utilizador: fechamos a curta à
        // mão para simular uma segunda sessão.
        $short->update(['ended_at' => '2026-10-10T11:30:00', 'ended_reason' => 'pause']);

        // 5 h aberta, mas com o canal in-app desligado.
        $user->notificationPreference()->update(['in_app_enabled' => false]);

        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $this->makeTask($user)->id,
            'started_at' => '2026-10-10T07:00:00',
            'created_at' => '2026-10-10T07:00:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function it_honours_the_user_timer_long_hours_preference(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $user->notificationPreference()->update(['timer_long_hours' => 8]);

        // 5 h aberta: abaixo das 8 h configuradas pelo utilizador.
        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $this->makeTask($user)->id,
            'started_at' => '2026-10-10T07:00:00',
            'created_at' => '2026-10-10T07:00:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();
        $this->assertSame(0, $user->notifications()->count());

        // Só pode haver uma entrada aberta por utilizador: fechamos a de 5 h
        // para simular uma nova sessão já acima do novo limite.
        TaskTimeEntry::query()->update(['ended_at' => '2026-10-10T09:00:00', 'ended_reason' => 'pause']);

        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $this->makeTask($user)->id,
            'started_at' => '2026-10-10T03:00:00',
            'created_at' => '2026-10-10T03:00:00',
        ]);

        $this->artisan('foco:check-timers')->assertSuccessful();
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function the_user_option_limits_the_run(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $first = $this->actingAsUser();
        $second = $this->switchTo();

        foreach ([$first, $second] as $user) {
            TaskTimeEntry::factory()->open()->create([
                'user_id' => $user->id,
                'task_id' => $this->makeTask($user)->id,
                'started_at' => '2026-10-10T07:00:00',
                'created_at' => '2026-10-10T07:00:00',
            ]);
        }

        $this->artisan('foco:check-timers', ['--user' => $first->id])->assertSuccessful();

        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(0, $second->notifications()->count());
    }

    #[Test]
    public function an_invalid_user_option_is_rejected_instead_of_running_for_everyone(): void
    {
        $first = $this->actingAsUser();
        $second = $this->switchTo();

        foreach ([$first, $second] as $user) {
            TaskTimeEntry::factory()->open()->create([
                'user_id' => $user->id,
                'task_id' => $this->makeTask($user)->id,
                'started_at' => now()->subHours(5),
                'created_at' => now()->subHours(5),
            ]);
        }

        // Um `--user` inválido tem de falhar: se fosse lido como "todos", o
        // comando mexia nos dados dos dois utilizadores sem pedir isso.
        $this->artisan('foco:check-timers', ['--user' => 'ze-pelo-nome'])
            ->expectsOutput('O --user tem de ser o ID (inteiro positivo) de um utilizador.')
            ->assertFailed();

        $this->assertSame(0, $first->notifications()->count());
        $this->assertSame(0, $second->notifications()->count());
    }

    /**
     * Cria e autentica um segundo utilizador dentro do mesmo teste.
     */
    private function switchTo(): User
    {
        $user = $this->actingAsUser();

        $this->app->make('auth')->forgetGuards();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(User $user, array $attributes = []): Task
    {
        return Task::factory()->create($attributes + ['user_id' => $user->id]);
    }
}
