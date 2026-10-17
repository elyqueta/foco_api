<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notifications\SendReminders;
use App\Enums\NotificationType;
use App\Models\NotificationDispatchLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\FocoNotification;
use App\Notifications\Messages;
use App\Services\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class NotificationMailTest extends TestCase
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
        return User::factory()->create($attributes)->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(User $user, array $attributes = []): Task
    {
        return Task::factory()->create($attributes + [
            'user_id' => $user->id,
            'title' => 'Enviar relatório',
            'due_date' => '2026-10-11',
            'due_time' => '15:00',
        ]);
    }

    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    private function log(?string $channel = null, ?string $status = null): Collection
    {
        $query = NotificationDispatchLog::query();

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    #[Test]
    public function the_mail_carries_the_pt_subject_the_view_and_the_links(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user);

        Notification::fake();

        $this->dispatcher()->dispatchChannels($user, NotificationType::TaskDueSoon, $task, 'due_soon:2026-10-11', ['email']);

        Notification::assertSentTo(
            $user,
            FocoNotification::class,
            function (FocoNotification $notification) use ($user, $task): bool {
                $mail = $notification->toMail($user);
                $content = $mail->content();

                return $mail->envelope()->subject === '[Foco] Tarefa a expirar: '.$task->title
                    && $content->markdown === 'mail.task-due-soon'
                    && $content->with['title'] === 'Tarefa a expirar'
                    && $content->with['body'] === '«Enviar relatório» expira hoje às 15:00.'
                    && $content->with['actionUrl'] === 'http://localhost:4200/tarefas/'.$task->id
                    && $content->with['actionLabel'] === 'Abrir no Foco'
                    && $content->with['settingsUrl'] === 'http://localhost:4200/definicoes';
            },
        );
    }

    #[Test]
    public function the_mail_of_a_type_without_a_dedicated_view_uses_the_generic_one(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user);

        Notification::fake();

        $this->dispatcher()->dispatchChannels(
            $user,
            NotificationType::TaskCompleted,
            $task,
            'completed:2026-10-11T09:00:00',
            ['email'],
        );

        Notification::assertSentTo(
            $user,
            FocoNotification::class,
            function (FocoNotification $notification) use ($user): bool {
                $content = $notification->toMail($user)->content();

                return $content->markdown === 'mail.generic'
                    && str_contains($content->with['title'], 'Tarefa concluída');
            },
        );
    }

    #[Test]
    public function the_mail_renders_in_pt_with_the_button_and_the_footer(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user);

        $notification = new FocoNotification(
            NotificationType::TaskDueSoon,
            ['title' => 'Tarefa a expirar', 'body' => '«Enviar relatório» expira hoje às 15:00.', 'taskId' => (string) $task->id],
            $task,
        );

        $html = $notification->toMail($user)->render();

        $this->assertStringContainsString('Abrir no Foco', $html);
        $this->assertStringContainsString('http://localhost:4200/tarefas/'.$task->id, $html);
        $this->assertStringContainsString('Recebes este email porque ativaste as notificações no Foco.', $html);
        $this->assertStringContainsString('http://localhost:4200/definicoes', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function the_mail_of_a_project_links_to_the_project(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Loja Nerd',
            'due_date' => '2026-10-05',
        ]);

        Notification::fake();

        $this->dispatcher()->dispatchChannels($user, NotificationType::ProjectOverdue, $project, 'overdue', ['email']);

        Notification::assertSentTo(
            $user,
            FocoNotification::class,
            function (FocoNotification $notification) use ($user, $project): bool {
                $content = $notification->toMail($user)->content();

                return $notification->toMail($user)->envelope()->subject === '[Foco] Projeto atrasado: Loja Nerd'
                    && $content->markdown === 'mail.project-overdue'
                    && $content->with['actionUrl'] === 'http://localhost:4200/projetos/'.$project->id;
            },
        );
    }

    #[Test]
    public function the_daily_cap_stops_the_emails_and_keeps_the_in_app_notifications(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        config(['services.scheduler.mail_daily_limit' => 1]);

        // Digest fora da hora: este teste é só dos lembretes.
        $user = $this->user();
        $user->notificationPreference->update(['digest_hour' => 20]);

        $this->task($user, ['title' => 'Primeira tarefa']);
        $this->task($user, ['title' => 'Segunda tarefa']);

        app(SendReminders::class)->forUser($user);

        // Uma in-app por tarefa: o teto diário só corta o canal email.
        $this->assertSame(2, $user->notifications()->count());

        $emails = $this->log(NotificationDispatcher::CHANNEL_EMAIL);

        $this->assertCount(2, $emails);
        $this->assertSame(1, $emails->where('status', 'sent')->count());
        $this->assertSame('daily_limit', $emails->where('status', 'skipped')->first()->error);
    }

    #[Test]
    public function more_than_five_individual_emails_are_aggregated_into_one(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();

        // Digest fora da hora: este teste é só dos lembretes.
        $user->notificationPreference->update(['digest_hour' => 20]);

        foreach (range(1, 6) as $index) {
            $this->task($user, ['title' => "Tarefa {$index}"]);
        }

        app(SendReminders::class)->forUser($user);

        // As seis in-app continuam a existir individualmente.
        $this->assertSame(6, $user->notifications()->count());

        $emails = $this->log(NotificationDispatcher::CHANNEL_EMAIL);

        // Seis skipped `batched` + o email agregado (`batched:{data local}`).
        $this->assertCount(7, $emails);
        $this->assertSame(6, $emails->where('status', 'skipped')->count());
        $this->assertSame('batched', $emails->where('status', 'skipped')->first()->error);

        $aggregated = $emails->where('status', 'sent')->firstOrFail();

        $this->assertSame('daily_digest', $aggregated->type);
        $this->assertSame('batched:2026-10-11', $aggregated->dedupe_key);
        $this->assertNull($aggregated->subject_id);
    }

    #[Test]
    public function a_failed_email_is_retried_without_repeating_the_in_app_notification(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user);

        // Primeiro envio: a construção da notificação falha (e-mail do
        // serviço em baixo) — nenhum canal é entregue.
        $this->instance(Messages::class, \Mockery::mock(Messages::class, function ($mock): void {
            $mock->shouldReceive('make')->andThrow(new RuntimeException('Serviço de email em baixo.'));
        }));

        $this->dispatcher()->dispatch($user, NotificationType::TaskDueSoon, $task, 'due_soon:2026-10-11');

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame('pending', $this->log(NotificationDispatcher::CHANNEL_EMAIL)->first()->status);

        // Segundo envio (tick seguinte): o serviço voltou, os dois canais
        // são entregues e nenhum é duplicado.
        $this->app->forgetInstance(Messages::class);

        $this->dispatcher()->dispatch($user, NotificationType::TaskDueSoon, $task, 'due_soon:2026-10-11');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, $this->log(NotificationDispatcher::CHANNEL_IN_APP)->count());
        $this->assertSame('sent', $this->log(NotificationDispatcher::CHANNEL_EMAIL)->first()->status);
    }

    #[Test]
    public function the_email_is_not_sent_when_the_channel_is_off_for_the_type(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user);

        $user->notificationPreference->update([
            'types' => ['task_due_soon' => ['email' => false, 'inApp' => true]],
        ]);

        $this->dispatcher()->dispatch($user, NotificationType::TaskDueSoon, $task, 'due_soon:2026-10-11');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertCount(0, $this->log(NotificationDispatcher::CHANNEL_EMAIL));
    }

    #[Test]
    public function the_mail_of_every_type_has_a_view(): void
    {
        foreach (NotificationType::cases() as $type) {
            $notification = new FocoNotification($type, ['title' => 'Título', 'body' => 'Corpo']);
            $mail = $notification->toMail($this->user());

            $this->assertTrue(view()->exists($mail->content()->markdown), "Sem vista para {$type->value}.");
        }
    }
}
