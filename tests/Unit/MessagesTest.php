<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Messages;
use App\Services\TimerService;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessagesTest extends TestCase
{
    private Messages $messages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messages = new Messages(new UserClock, new TimerService);
    }

    #[Test]
    public function it_builds_the_title_and_body_of_every_type(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T09:00:00'); // 10:00 em Luanda

        $user = $this->user('Zua', 'Africa/Luanda');

        $task = $this->task($user, [
            'title' => 'Integrar API',
            'due_date' => '2026-10-11',
            'due_time' => '14:30',
            'estimate_minutes' => 30,
            'tracked_seconds' => 3600,
        ]);

        $this->assertSame(
            ['title' => 'Tarefa a expirar', 'body' => '«Integrar API» expira hoje às 14:30.', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskDueSoon, $task, []),
        );

        $this->assertSame(
            ['title' => 'Prazo daqui a pouco', 'body' => '«Integrar API» expira às 14:30 (em 90 min).', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskDueImminent, $task, ['minutes' => 90]),
        );

        $this->assertSame(
            ['title' => 'Tarefa expirada', 'body' => '«Integrar API» passou do prazo (11 out 2026).', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskExpired, $task, []),
        );

        $this->assertSame(
            ['title' => 'Tarefa concluída 🎉', 'body' => 'Concluíste «Integrar API» — tempo registado: 1h 0m.', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskCompleted, $task, ['trackedSeconds' => 3600]),
        );

        $this->assertSame(
            ['title' => 'Tarefa concluída 🎉', 'body' => 'Concluíste «Integrar API».', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskCompleted, $task, ['trackedSeconds' => 0]),
        );

        $this->assertSame(
            ['title' => 'Tempo estimado ultrapassado', 'body' => '«Integrar API»: 1h 0m registados para uma estimativa de 30 min.', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskEstimateExceeded, $task, []),
        );

        $this->assertSame(
            ['title' => 'Timer ainda a correr', 'body' => 'O tempo de «Integrar API» está a correr há 5h 30m. Queres pausar?', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TimerRunningLong, $task, ['trackedSeconds' => 19_800]),
        );

        $this->assertSame(
            ['title' => 'Tarefa adiada em atraso', 'body' => '«Integrar API» foi adiada para 11 out 2026 às 14:30 e já passou.', 'taskId' => $task->id],
            $this->messages->make($user, NotificationType::TaskOverduePostponed, $task, []),
        );
    }

    #[Test]
    public function it_uses_today_and_tomorrow_in_the_users_timezone(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T20:00:00'); // 21:00 em Luanda

        $user = $this->user('Zua', 'Africa/Luanda');

        // As tarefas do teste nunca são persistidas: o `id` é definido à mão
        // apenas para ver o `data` da notificação.
        $today = $this->task($user, ['title' => 'Hoje', 'due_date' => '2026-10-11']);
        $today->id = Str::uuid()->toString();
        $tomorrow = $this->task($user, ['title' => 'Amanhã', 'due_date' => '2026-10-12', 'due_time' => '09:00']);
        $tomorrow->id = Str::uuid()->toString();
        $later = $this->task($user, ['title' => 'Depois', 'due_date' => '2026-10-15']);
        $later->id = Str::uuid()->toString();

        $this->assertStringContainsString('expira hoje.', $this->body($user, NotificationType::TaskDueSoon, $today));
        $this->assertStringContainsString('expira amanhã às 09:00.', $this->body($user, NotificationType::TaskDueSoon, $tomorrow));
        $this->assertStringContainsString('expira 15 out 2026.', $this->body($user, NotificationType::TaskDueSoon, $later));
    }

    #[Test]
    public function it_formats_project_messages(): void
    {
        CarbonImmutable::setTestNow('2026-10-11T09:00:00');

        $user = $this->user('Zua', 'Africa/Luanda');

        $project = new Project([
            'name' => 'Loja Nerd',
            'due_date' => '2026-10-11',
        ]);
        $project->id = Str::uuid()->toString();

        $this->assertSame(
            ['title' => 'Projeto a expirar', 'body' => '«Loja Nerd» tem prazo hoje.', 'projectId' => $project->id],
            $this->messages->make($user, NotificationType::ProjectDueSoon, $project, []),
        );

        $this->assertSame(
            ['title' => 'Projeto atrasado', 'body' => '«Loja Nerd» passou do prazo (11 out 2026).', 'projectId' => $project->id],
            $this->messages->make($user, NotificationType::ProjectOverdue, $project, []),
        );

        $this->assertSame(
            ['title' => 'Projeto pronto a concluir', 'body' => 'Todas as tarefas de «Loja Nerd» estão concluídas. Marcar o projeto como concluído?', 'projectId' => $project->id],
            $this->messages->make($user, NotificationType::ProjectReadyToComplete, $project, []),
        );
    }

    #[Test]
    public function it_falls_back_to_a_generic_digest_without_a_summary(): void
    {
        $user = $this->user('Zua', 'Africa/Luanda');

        $this->assertSame(
            ['title' => 'O teu dia', 'body' => 'Aqui está o resumo do teu dia no Foco.'],
            $this->messages->make($user, NotificationType::DailyDigest, null, []),
        );

        $this->assertSame(
            ['title' => 'O teu dia', 'body' => 'Tens 2 tarefas para hoje e 1 atrasada.'],
            $this->messages->make($user, NotificationType::DailyDigest, null, ['summary' => 'Tens 2 tarefas para hoje e 1 atrasada.']),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(string $name, string $timezone): User
    {
        return new User(['name' => $name, 'timezone' => $timezone]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(User $user, array $attributes): Task
    {
        $task = new Task($attributes);
        $task->id = Str::uuid()->toString();
        $task->setRelation('user', $user);

        return $task;
    }

    private function body(User $user, NotificationType $type, Task|Project $subject): string
    {
        return (string) $this->messages->make($user, $type, $subject, [])['body'];
    }
}
