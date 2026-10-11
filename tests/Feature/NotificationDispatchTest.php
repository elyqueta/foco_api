<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Tasks\ExpireOverdueTasks;
use App\Enums\NotificationType;
use App\Models\NotificationDispatchLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Notifications\Messages;
use App\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ActsAsUser;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function completing_a_task_creates_the_notification_and_the_log_row(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'title' => 'Integrar API',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertSame(1, $user->notifications()->count());

        $notification = $user->notifications()->first();

        $this->assertSame('task_completed', $notification->type);
        $this->assertSame('Tarefa concluída 🎉', $notification->data['title']);
        $this->assertSame('Concluíste «Integrar API».', $notification->data['body']);
        $this->assertSame($task->id, $notification->data['taskId']);

        $log = NotificationDispatchLog::first();

        $this->assertSame('task_completed', $log->type);
        $this->assertSame('in_app', $log->channel);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
    }

    #[Test]
    public function the_completion_message_includes_the_registered_time(): void
    {
        $user = $this->actingAsUser();

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'title' => 'Integrar API',
            'estimate_minutes' => 30,
        ]);

        $entry = TaskTimeEntry::factory()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subMinutes(95),
            'ended_at' => null,
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        // A tarefa concluída tem a estimativa ultrapassada: duas notificações.
        $this->assertSame(2, $user->notifications()->count());

        $this->assertSame(
            'Concluíste «Integrar API» — tempo registado: 1h 35m.',
            $user->notifications()->where('type', 'task_completed')->first()->data['body'],
        );

        $this->assertSame(
            '«Integrar API»: 1h 35m registados para uma estimativa de 30 min.',
            $user->notifications()->where('type', 'task_estimate_exceeded')->first()->data['body'],
        );
    }

    #[Test]
    public function the_last_task_of_a_project_notifies_ready_to_complete(): void
    {
        $user = $this->actingAsUser();

        $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd']);

        Task::factory()->done()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        $second = Task::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$second->id}/complete")
            ->assertOk()
            ->assertJson(['suggestCompleteProject' => ['id' => $project->id, 'name' => 'Loja Nerd']]);

        $this->assertSame(2, $user->notifications()->count());

        $notification = $user->notifications()->where('type', 'project_ready_to_complete')->first();

        $this->assertNotNull($notification);
        $this->assertSame('Todas as tarefas de «Loja Nerd» estão concluídas. Marcar o projeto como concluído?', $notification->data['body']);
        $this->assertSame($project->id, $notification->data['projectId']);
    }

    #[Test]
    public function pausing_a_task_that_exceeded_the_estimate_warns_once(): void
    {
        $user = $this->actingAsUser();

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'title' => 'Integrar API',
            'estimate_minutes' => 30,
            'tracked_seconds' => 0,
        ]);

        TaskTimeEntry::factory()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subMinutes(45),
            'ended_at' => null,
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/timer/pause")->assertOk();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('task_estimate_exceeded', $user->notifications()->first()->type);

        // Uma segunda pausa (depois de retomar) não repete o aviso: a
        // dedupe é `estimate:{minutos}`.
        $this->postJson("/api/v1/tasks/{$task->id}/timer/resume")->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/timer/pause")->assertOk();

        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function expiring_a_task_notifies_once_per_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->actingAsUser();

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'todo',
            'title' => 'Enviar relatório',
            'due_date' => '2026-10-10',
        ]);

        app(ExpireOverdueTasks::class)->forUser($user);

        $this->assertSame(1, $user->notifications()->count());

        $notification = $user->notifications()->first();

        $this->assertSame('task_expired', $notification->type);
        $this->assertSame('Tarefa expirada', $notification->data['title']);
        $this->assertSame('«Enviar relatório» passou do prazo (10 out 2026).', $notification->data['body']);

        // Reexpirar (a tarefa já está `expired`) não cria mais nada.
        app(ExpireOverdueTasks::class)->forUser($user);

        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function repeating_the_same_event_does_not_duplicate_the_notification(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $dispatcher = app(NotificationDispatcher::class);

        $context = ['trackedSeconds' => 0];

        $this->assertTrue($dispatcher->dispatch($user, NotificationType::TaskCompleted, $task, 'completed:test', $context));
        $this->assertFalse($dispatcher->dispatch($user, NotificationType::TaskCompleted, $task, 'completed:test', $context));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, NotificationDispatchLog::count());

        // Evento diferente (chave de dedupe diferente) notifica outra vez.
        $this->assertTrue($dispatcher->dispatch($user, NotificationType::TaskCompleted, $task, 'completed:other', $context));

        $this->assertSame(2, $user->notifications()->count());
    }

    #[Test]
    public function disabled_in_app_creates_no_notification_and_no_log_row(): void
    {
        $user = $this->actingAsUser();

        $user->notificationPreference->update(['in_app_enabled' => false]);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, NotificationDispatchLog::count());
    }

    #[Test]
    public function a_type_override_takes_precedence_over_the_type_default(): void
    {
        $user = $this->actingAsUser();

        // O default de `task_completed` é in-app; desligar no utilizador
        // silencia o tipo mesmo que o canal esteja activo.
        $user->notificationPreference->update([
            'in_app_enabled' => true,
            'types' => ['task_completed' => ['inApp' => false, 'email' => false]],
        ]);

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, NotificationDispatchLog::count());
    }

    #[Test]
    public function a_type_that_off_by_default_can_be_turned_on_by_the_user(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        // `task_due_soon` é ligado pelo utilizador (o default é off).
        $user->notificationPreference->update([
            'in_app_enabled' => true,
            'email_enabled' => true,
            'types' => ['task_due_soon' => ['inApp' => true, 'email' => true]],
        ]);

        $this->assertTrue(
            app(NotificationDispatcher::class)->dispatch($user, NotificationType::TaskDueSoon, $task, 'due:test'),
        );

        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function a_failed_send_is_retried_up_to_three_times(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $this->mock(Messages::class, function ($mock): void {
            $mock->shouldReceive('make')->andThrow(new RuntimeException('Resend em baixo.'));
        });

        $dispatcher = app(NotificationDispatcher::class);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertFalse($dispatcher->dispatch(
                $user,
                NotificationType::TaskCompleted,
                $task,
                'completed:test',
            ));
        }

        $this->assertSame(0, $user->notifications()->count());

        $log = NotificationDispatchLog::first();

        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertSame(3, $log->attempts);
        $this->assertSame('Resend em baixo.', $log->error);
        $this->assertSame(1, NotificationDispatchLog::count());
    }

    #[Test]
    public function a_channel_already_sent_is_not_repeated_when_the_next_send_would_fail(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $dispatcher = app(NotificationDispatcher::class);

        $this->assertTrue($dispatcher->dispatch($user, NotificationType::TaskCompleted, $task, 'completed:test'));
        $this->assertSame(1, $user->notifications()->count());

        $this->mock(Messages::class, function ($mock): void {
            $mock->shouldReceive('make')->andThrow(new RuntimeException('Resend em baixo.'));
        });

        // O canal já está `sent`: o dispatcher não chega a tentar enviar.
        $this->assertFalse($dispatcher->dispatch($user, NotificationType::TaskCompleted, $task, 'completed:test'));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('sent', NotificationDispatchLog::first()->status);
        $this->assertSame(1, NotificationDispatchLog::first()->attempts);
    }

    #[Test]
    public function a_failing_dispatcher_never_breaks_the_request(): void
    {
        $user = $this->actingAsUser();

        $this->mock(Messages::class, function ($mock): void {
            $mock->shouldReceive('make')->andThrow(new RuntimeException('Resend em baixo.'));
        });

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertSame('done', $task->fresh()->status->value);
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function notifications_do_not_leak_between_users(): void
    {
        $user = $this->actingAsUser();
        $other = User::factory()->create();

        $task = Task::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
        ]);

        $this->postJson("/api/v1/tasks/{$task->id}/complete")->assertOk();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
    }
}
