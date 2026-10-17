<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notifications\SendReminders;
use App\Enums\TaskStatus;
use App\Models\NotificationDispatchLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.frontend.url' => 'http://localhost:4200']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(User $user, array $attributes = []): Task
    {
        return Task::factory()->create($attributes + ['user_id' => $user->id]);
    }

    private function reminders(User $user): int
    {
        return app(SendReminders::class)->forUser($user);
    }

    /**
     * @return array<string, string>
     */
    private function notification(User $user): array
    {
        $notification = $user->notifications()->orderByDesc('created_at')->first();

        $this->assertNotNull($notification, 'Esperava uma notificação in-app.');

        return ['type' => (string) $notification->type, 'body' => (string) $notification->data['body']];
    }

    #[Test]
    public function a_task_due_today_is_reminded_before_its_deadline(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00'); // 11:00 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'status' => 'todo', 'due_date' => '2026-10-11', 'due_time' => '15:00']);

        $this->assertSame(1, $this->reminders($user));

        $notification = $this->notification($user);

        $this->assertSame('task_due_soon', $notification['type']);
        $this->assertSame('«Enviar relatório» expira hoje às 15:00.', $notification['body']);

        // O email segue também (chave do dia).
        $email = NotificationDispatchLog::firstWhere('channel', 'email');

        $this->assertNotNull($email);
        $this->assertSame('task_due_soon', $email->type);
        $this->assertSame('sent', $email->status);
    }

    #[Test]
    public function a_task_due_tomorrow_without_a_time_is_reminded_from_18h_the_day_before(): void
    {
        // 10:00: a janela de 24 h chegaria às 23:59 de hoje (a meia-noite);
        // com a marca das 18:00 do dia anterior, o aviso só sai à noite.
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Pagar conta', 'due_date' => '2026-10-12', 'due_time' => null]);

        $this->assertSame(0, $this->reminders($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function a_task_due_tomorrow_without_a_time_is_reminded_in_the_evening_before(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T18:30:00'); // 19:30 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Pagar conta', 'due_date' => '2026-10-12', 'due_time' => null]);

        $this->assertSame(1, $this->reminders($user));

        $notification = $this->notification($user);

        $this->assertSame('task_due_soon', $notification['type']);
        $this->assertSame('«Pagar conta» expira amanhã.', $notification['body']);
    }

    #[Test]
    public function a_task_due_today_without_a_time_is_reminded_during_the_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Pagar conta', 'due_date' => '2026-10-11', 'due_time' => null]);

        $this->assertSame(1, $this->reminders($user));
        $this->assertSame('«Pagar conta» expira hoje.', $this->notification($user)['body']);
    }

    #[Test]
    public function a_task_about_to_be_due_is_also_reminded_as_imminent(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00'); // 11:00 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Chamar o Zua', 'due_date' => '2026-10-11', 'due_time' => '11:45']);

        $this->assertSame(2, $this->reminders($user));

        $imminent = $user->notifications()->where('type', 'task_due_imminent')->first();

        $this->assertNotNull($imminent);
        $this->assertSame('«Chamar o Zua» expira às 11:45 (em 45 min).', $imminent->data['body']);
    }

    #[Test]
    public function a_task_whose_deadline_has_passed_is_not_reminded_as_due_soon(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-10', 'due_time' => '09:00']);

        $this->assertSame(0, $this->reminders($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function a_postponed_task_whose_date_has_passed_warns_once(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, [
            'title' => 'Comprar material',
            'status' => TaskStatus::Postponed,
            'due_date' => '2026-10-09',
            'due_time' => null,
        ]);

        $this->assertSame(1, $this->reminders($user));

        $notification = $this->notification($user);

        $this->assertSame('task_overdue_postponed', $notification['type']);
        $this->assertSame('«Comprar material» foi adiada para 9 out 2026 e já passou.', $notification['body']);

        // Um segundo lembrete não repete o aviso: o plano continua a existir,
        // mas a dedupe (`overdue_postponed:{data}`) não cria outra
        // notificação.
        $this->assertSame(1, $this->reminders($user));
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function a_postponed_task_with_a_new_date_warns_again(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user, [
            'title' => 'Comprar material',
            'status' => TaskStatus::Postponed,
            'due_date' => '2026-10-09',
            'due_time' => null,
        ]);

        $this->assertSame(1, $this->reminders($user));

        // Adiada para outra data que também já passou: novo aviso.
        $task->update(['due_date' => '2026-10-08']);

        $this->assertSame(1, $this->reminders($user));
        $this->assertSame(2, $user->notifications()->count());
    }

    #[Test]
    public function a_project_due_today_is_reminded_from_the_digest_hour(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T07:00:00'); // 08:00 em Luanda (digest_hour)

        $user = $this->user();
        Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd', 'due_date' => '2026-10-11']);

        $this->assertSame(1, $this->reminders($user));

        $notification = $this->notification($user);

        $this->assertSame('project_due_soon', $notification['type']);
        $this->assertSame('«Loja Nerd» tem prazo hoje.', $notification['body']);
    }

    #[Test]
    public function a_project_due_today_is_not_reminded_before_the_digest_hour(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T06:30:00'); // 07:30 em Luanda

        $user = $this->user();
        Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd', 'due_date' => '2026-10-11']);

        $this->assertSame(0, $this->reminders($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function a_project_due_tomorrow_is_reminded_from_18h(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T16:30:00'); // 17:30 em Luanda

        $user = $this->user();
        Project::factory()->create(['user_id' => $user->id, 'name' => 'Loja Nerd', 'due_date' => '2026-10-12']);

        $this->assertSame(0, $this->reminders($user));

        CarbonImmutable::setTestNow('2026-10-11T17:30:00'); // 18:30 em Luanda

        $this->assertSame(1, $this->reminders($user));

        $notification = $this->notification($user);

        $this->assertSame('project_due_soon', $notification['type']);
        $this->assertSame('«Loja Nerd» tem prazo amanhã.', $notification['body']);
    }

    #[Test]
    public function an_overdue_project_is_notified_once_and_marked(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Loja Nerd',
            'status' => 'active',
            'due_date' => '2026-10-05',
        ]);

        $this->assertSame(1, $this->reminders($user));

        $this->assertNotNull($project->fresh()->overdue_notified_at);
        $this->assertSame('project_overdue', $this->notification($user)['type']);

        // Marcado como avisado: o segundo lembrete não repete.
        $this->assertSame(0, $this->reminders($user));
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function an_overdue_project_that_fails_to_send_is_not_marked(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Loja Nerd',
            'status' => 'active',
            'due_date' => '2026-10-05',
        ]);

        // In-app desligado: a notificação nunca é entregue, por isso o
        // projeto continua por avisar e o tick seguinte tenta outra vez.
        $user->notificationPreference->update([
            'types' => ['project_overdue' => ['email' => false, 'inApp' => false]],
        ]);

        $this->assertSame(1, $this->reminders($user));

        $this->assertNull($project->fresh()->overdue_notified_at);
    }

    #[Test]
    public function the_reminder_uses_the_user_timezone(): void
    {
        // 2026-10-11T02:00:00Z = 22:00 do dia 10 em Nova Iorque (UTC-4).
        CarbonImmutable::setTestNow('2026-10-11T02:00:00');

        $user = $this->user(['timezone' => 'America/New_York']);
        $this->task($user, ['title' => 'Pagar conta', 'due_date' => '2026-10-10', 'due_time' => null]);

        $this->assertSame(1, $this->reminders($user));

        // Em Luanda já passou para o dia 11 e o prazo era às 23:59 do dia
        // 10 — o mesmo utilizador noutro fuso não é avisado.
        $local = $this->user(['timezone' => 'Africa/Luanda']);
        $this->task($local, ['title' => 'Pagar conta', 'due_date' => '2026-10-10', 'due_time' => null]);

        $this->assertSame(0, $this->reminders($local));
        $this->assertSame('«Pagar conta» expira hoje.', $this->notification($user)['body']);
    }

    #[Test]
    public function a_done_task_is_not_reminded(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'status' => 'done', 'due_date' => '2026-10-11', 'due_time' => '15:00']);

        $this->assertSame(0, $this->reminders($user));
    }

    #[Test]
    public function a_reminder_respects_the_user_preferences(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11', 'due_time' => '15:00']);

        $user->notificationPreference->update([
            'types' => ['task_due_soon' => ['email' => false, 'inApp' => false]],
        ]);

        $this->assertSame(1, $this->reminders($user));
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, NotificationDispatchLog::count());
    }

    #[Test]
    public function the_window_follows_the_user_preference(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00'); // 11:00 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-12', 'due_time' => '09:00']);

        // Prazo a 22 h: dentro das 24 h por omissão.
        $this->assertSame(1, $this->reminders($user));

        $user->notifications()->delete();

        // Com uma janela de 2 h, o mesmo prazo já não entra.
        $user->notificationPreference->update(['due_soon_hours' => 2]);

        $this->assertSame(0, $this->reminders($user));
    }
}
