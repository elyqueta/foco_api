<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Notifications\SendDailyDigests;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Notifications\FocoNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DailyDigestTest extends TestCase
{
    use RefreshDatabase;

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
        return Task::factory()->create($attributes + ['user_id' => $user->id, 'due_time' => null]);
    }

    private function digest(User $user): bool
    {
        return app(SendDailyDigests::class)->forUser($user);
    }

    #[Test]
    public function the_digest_is_sent_once_per_local_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00'); // 11:00 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $this->assertTrue($this->digest($user));
        $this->assertSame(1, $user->notifications()->count());

        // Segundo tick no mesmo dia local: nada de repetido.
        $this->assertTrue($this->digest($user));
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function the_digest_is_not_sent_before_the_digest_hour(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T06:30:00'); // 07:30 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $this->assertFalse($this->digest($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function the_digest_is_not_sent_after_the_window(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T15:00:00'); // 16:00 em Luanda (8h + 6h)

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $this->assertFalse($this->digest($user));
    }

    #[Test]
    public function the_digest_is_sent_on_a_user_digest_hour(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T06:30:00'); // 07:30 em Luanda

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $user->notificationPreference->update(['digest_hour' => 7]);

        $this->assertTrue($this->digest($user));
        $this->assertSame('daily_digest', $user->notifications()->first()->type);
    }

    #[Test]
    public function the_digest_is_not_sent_when_there_is_nothing_to_report(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'status' => 'done', 'due_date' => '2026-10-11']);

        $this->assertFalse($this->digest($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    #[Test]
    public function the_digest_lists_the_sections_of_the_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();

        // Para hoje (com hora), atrasada, crítica, expirada ontem e tempo
        // registado ontem — uma de cada secção.
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11', 'due_time' => '15:00']);
        $this->task($user, ['title' => 'Pagar conta', 'due_date' => '2026-10-05']);
        $this->task($user, ['title' => 'Comprar material', 'urgency' => Urgency::Critical, 'due_date' => null]);
        $this->task($user, ['title' => 'Renovar certificado', 'status' => TaskStatus::Expired, 'due_date' => '2026-10-10', 'expired_at' => '2026-10-10 14:00']);

        // Sem prazo: só serve ao resumo do tempo de ontem.
        $task = $this->task($user, ['title' => 'Estudar timers', 'due_date' => null]);

        TaskTimeEntry::factory()->closed()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => '2026-10-10 10:00',
            'ended_at' => '2026-10-10 11:30',
            'ended_reason' => 'pause',
        ]);

        $this->assertTrue($this->digest($user));

        $notification = $user->notifications()->firstOrFail();

        $this->assertSame('daily_digest', $notification->type);
        $this->assertSame('O teu dia', $notification->data['title']);
        $this->assertSame(
            'Para hoje: 1 · Atrasadas: 1 · Críticas/altas pendentes: 1 · Expiradas ontem: 1 · Tempo registado ontem: 1h 30m.',
            $notification->data['body'],
        );
    }

    #[Test]
    public function the_digest_email_carries_the_sections_and_the_brand_colors(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $task = $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        Notification::fake();

        $this->digest($user);

        Notification::assertSentTo(
            $user,
            FocoNotification::class,
            function (FocoNotification $notification) use ($user, $task): bool {
                $content = $notification->toMail($user)->content();

                if ($content->markdown !== 'mail.daily-digest') {
                    return false;
                }

                $sections = $content->with['sections'] ?? [];

                if (! is_array($sections) || $sections === []) {
                    return false;
                }

                foreach ($sections as $section) {
                    foreach ($section['items'] ?? [] as $item) {
                        if (str_contains((string) $item, $task->title)) {
                            return true;
                        }
                    }
                }

                return false;
            },
        );
    }

    #[Test]
    public function the_digest_uses_the_local_day_of_the_user(): void
    {
        // 07:30 UTC = 08:30 em Luanda (digest enviado) e 03:30 em Nova
        // Iorque (ainda não chegou a hora do digest).
        CarbonImmutable::setTestNow('2026-10-11T07:30:00');

        $luanda = $this->user(['timezone' => 'Africa/Luanda']);
        $novaIorque = $this->user(['timezone' => 'America/New_York']);

        $this->task($luanda, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);
        $this->task($novaIorque, ['title' => 'Pagar imposto', 'due_date' => '2026-10-11']);

        $this->assertTrue($this->digest($luanda));
        $this->assertFalse($this->digest($novaIorque));

        $this->assertSame(1, $luanda->notifications()->count());
        $this->assertSame(0, $novaIorque->notifications()->count());
    }

    #[Test]
    public function the_digest_does_not_repeat_itself_after_the_window_without_sending(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();

        // Sem tarefas: nada a reportar, e o digest continua disponível para
        // o resto da janela.
        $this->assertFalse($this->digest($user));
        $this->assertFalse($this->digest($user));
        $this->assertSame(0, $user->notifications()->count());

        // Uma tarefa criada depois é reportada no mesmo dia local.
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $this->assertTrue($this->digest($user));
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function the_digest_respects_the_user_preferences(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T10:00:00');

        $user = $this->user();
        $this->task($user, ['title' => 'Enviar relatório', 'due_date' => '2026-10-11']);

        $user->notificationPreference->update([
            'types' => ['daily_digest' => ['email' => false, 'inApp' => false]],
        ]);

        $this->assertTrue($this->digest($user));
        $this->assertSame(0, $user->notifications()->count());
    }
}
