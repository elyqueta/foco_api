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
use Tests\ActsAsUser;
use Tests\TestCase;

class NotificationsApiTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function it_lists_notifications_most_recent_first(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $older = $this->notification($user, $task, '2026-10-10T09:00:00');
        $newer = $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newer->id)
            ->assertJsonPath('1.id', $older->id)
            ->assertJsonPath('0.type', 'task_completed')
            ->assertJsonPath('0.title', 'Tarefa concluída')
            ->assertJsonPath('0.body', 'Concluíste a tarefa.')
            ->assertJsonPath('0.data.taskId', $task->id)
            ->assertJsonPath('0.readAt', null)
            ->assertJsonPath('0.createdAt', '2026-10-11T09:00:00Z');
    }

    #[Test]
    public function it_filters_unread_and_limits_the_page(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $this->notification($user, $task, '2026-10-10T09:00:00');
        $unread = $this->notification($user, $task, '2026-10-11T09:00:00');
        $read = $this->notification($user, $task, '2026-10-11T10:00:00');

        $read->markAsRead();

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $unread->id);

        $this->getJson('/api/v1/notifications?limit=2')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/v1/notifications?limit=1')->assertOk()->assertJsonCount(1);

        $this->getJson('/api/v1/notifications?limit=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    #[Test]
    public function it_paginates_backwards_with_the_before_cursor(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $first = $this->notification($user, $task, '2026-10-08T09:00:00');
        $second = $this->notification($user, $task, '2026-10-09T09:00:00');
        $third = $this->notification($user, $task, '2026-10-10T09:00:00');

        $this->getJson("/api/v1/notifications?before={$second->id}&limit=2")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $first->id);

        $this->getJson("/api/v1/notifications?before={$third->id}")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $second->id)
            ->assertJsonPath('1.id', $first->id);

        $this->getJson('/api/v1/notifications?before='.Carbon::now()->format('Y-m-d'))
            ->assertStatus(422);
    }

    #[Test]
    public function it_counts_the_unread_notifications(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $this->notification($user, $task, '2026-10-10T09:00:00');
        $unread = $this->notification($user, $task, '2026-10-11T09:00:00');

        $unread->markAsRead();

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    #[Test]
    public function it_marks_one_notification_as_read_idempotently(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $notification = $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJson(['message' => 'Notificação marcada como lida.']);

        $this->assertNotNull($notification->fresh()->read_at);

        // Marcar de novo não é erro.
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->getJson('/api/v1/notifications/unread-count')->assertJson(['count' => 0]);
    }

    #[Test]
    public function it_marks_all_notifications_as_read(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $this->notification($user, $task, '2026-10-10T09:00:00');
        $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJson(['message' => 'Todas as notificações foram marcadas como lidas.']);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(2, $user->notifications()->count());
    }

    #[Test]
    public function it_deletes_one_notification(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $notification = $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJson(['message' => 'Notificação removida.']);

        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function it_deletes_all_notifications(): void
    {
        $user = $this->actingAsUser();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $this->notification($user, $task, '2026-10-10T09:00:00');
        $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->deleteJson('/api/v1/notifications')
            ->assertOk()
            ->assertJson(['message' => 'Notificações removidas.']);

        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function notifications_of_another_user_are_not_found(): void
    {
        $user = $this->actingAsUser();
        $other = User::factory()->create();
        $task = Task::factory()->create(['user_id' => $user->id]);

        $notification = $this->notification($user, $task, '2026-10-11T09:00:00');

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->actingAs($other);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(404)
            ->assertJson(['message' => 'Não encontrado.']);

        $this->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertStatus(404)
            ->assertJson(['message' => 'Não encontrado.']);
    }

    #[Test]
    public function a_malformed_id_is_answered_with_404(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/notifications/nao-e-uuid/read')
            ->assertStatus(404)
            ->assertJson(['message' => 'Não encontrado.']);

        $this->deleteJson('/api/v1/notifications/nao-e-uuid')
            ->assertStatus(404)
            ->assertJson(['message' => 'Não encontrado.']);
    }

    #[Test]
    public function notification_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401)->assertJson(['message' => 'Não autenticado.']);
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
        $this->deleteJson('/api/v1/notifications')->assertStatus(401);
    }

    private function notification(
        User $user,
        Task $task,
        string $createdAt,
    ): DatabaseNotification {
        return $user->notifications()->create([
            // O `id` é um UUID gerado por quem envia (o canal `database` do
            // Laravel atribui-o a partir da notificação); num insert directo
            // tem de ser dado.
            'id' => Str::uuid()->toString(),
            'type' => 'task_completed',
            'data' => [
                'title' => 'Tarefa concluída',
                'body' => 'Concluíste a tarefa.',
                'taskId' => $task->id,
            ],
            'read_at' => null,
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ]);
    }
}
