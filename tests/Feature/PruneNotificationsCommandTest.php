<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_removes_read_notifications_older_than_30_days(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $old = $this->notification($user, $task, now()->subDays(40));
        $old->markAsRead();

        $recent = $this->notification($user, $task, now()->subDays(10));
        $recent->markAsRead();

        $this->artisan('foco:prune-notifications')
            ->expectsOutput('Notificações lidas removidas (> 30 dias): 1')
            ->expectsOutput('Notificações antigas removidas (> 90 dias): 0')
            ->assertSuccessful();

        $this->assertNotNull($recent->fresh());
        $this->assertNull($old->fresh());
    }

    #[Test]
    public function it_keeps_unread_notifications_until_the_90_day_ceiling(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $unread = $this->notification($user, $task, now()->subDays(45));
        $ancient = $this->notification($user, $task, now()->subDays(95));

        $this->artisan('foco:prune-notifications')
            ->expectsOutput('Notificações lidas removidas (> 30 dias): 0')
            ->expectsOutput('Notificações antigas removidas (> 90 dias): 1')
            ->assertSuccessful();

        // Não lida e com 45 dias: mantém-se (a limpeza de 30 dias só olha às
        // lidas; o tecto duro de 90 dias é que a levaria).
        $this->assertNotNull($unread->fresh());
        $this->assertNull($ancient->fresh());
    }

    #[Test]
    public function a_read_notification_older_than_90_days_is_counted_once(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $old = $this->notification($user, $task, now()->subDays(120));
        $old->markAsRead();

        $this->artisan('foco:prune-notifications')
            ->expectsOutput('Notificações lidas removidas (> 30 dias): 1')
            ->expectsOutput('Notificações antigas removidas (> 90 dias): 0')
            ->assertSuccessful();

        $this->assertNull($old->fresh());
    }

    #[Test]
    public function it_prunes_the_notifications_of_every_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->notification($user, Task::factory()->create(['user_id' => $user->id]), now()->subDays(100));
        $theirs = $this->notification($other, Task::factory()->create(['user_id' => $other->id]), now()->subDays(100));

        $this->artisan('foco:prune-notifications')->assertSuccessful();

        $this->assertNull($mine->fresh());
        $this->assertNull($theirs->fresh());
    }

    private function notification(User $user, Task $task, Carbon $createdAt): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => Str::uuid()->toString(),
            'type' => 'task_completed',
            'data' => ['title' => 'Tarefa concluída', 'body' => 'Concluíste a tarefa.', 'taskId' => $task->id],
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
