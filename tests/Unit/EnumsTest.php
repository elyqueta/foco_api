<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActivityType;
use App\Enums\NotificationType;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TimerState;
use App\Enums\Urgency;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    #[Test]
    public function urgency_values_and_labels(): void
    {
        $this->assertSame('critical', Urgency::Critical->value);
        $this->assertSame('high', Urgency::High->value);
        $this->assertSame('medium', Urgency::Medium->value);
        $this->assertSame('low', Urgency::Low->value);

        $this->assertSame(0, Urgency::Critical->order());
        $this->assertSame(1, Urgency::High->order());
        $this->assertSame(2, Urgency::Medium->order());
        $this->assertSame(3, Urgency::Low->order());

        $this->assertSame('Crítica', Urgency::Critical->label());
        $this->assertSame('Alta', Urgency::High->label());
        $this->assertSame('Média', Urgency::Medium->label());
        $this->assertSame('Baixa', Urgency::Low->label());

        $this->assertSame(Urgency::Medium, Urgency::fromOrder(2));
        $this->assertSame(Urgency::Critical, Urgency::fromOrder(0));
    }

    #[Test]
    public function task_status_values_and_labels(): void
    {
        $this->assertSame('todo', TaskStatus::Todo->value);
        $this->assertSame('in_progress', TaskStatus::InProgress->value);
        $this->assertSame('done', TaskStatus::Done->value);
        $this->assertSame('postponed', TaskStatus::Postponed->value);
        $this->assertSame('expired', TaskStatus::Expired->value);

        $this->assertSame('A fazer', TaskStatus::Todo->label());
        $this->assertSame('Em curso', TaskStatus::InProgress->label());
        $this->assertSame('Concluída', TaskStatus::Done->label());
        $this->assertSame('Adiada', TaskStatus::Postponed->label());
        $this->assertSame('Expirada', TaskStatus::Expired->label());

        $this->assertTrue(TaskStatus::Todo->isOpen());
        $this->assertTrue(TaskStatus::InProgress->isOpen());
        $this->assertTrue(TaskStatus::Postponed->isOpen());
        $this->assertFalse(TaskStatus::Done->isOpen());
        $this->assertFalse(TaskStatus::Expired->isOpen());

        $this->assertTrue(TaskStatus::Done->isCompleted());
        $this->assertFalse(TaskStatus::Todo->isCompleted());
    }

    #[Test]
    public function project_status_values_and_labels(): void
    {
        $this->assertSame('active', ProjectStatus::Active->value);
        $this->assertSame('paused', ProjectStatus::Paused->value);
        $this->assertSame('done', ProjectStatus::Done->value);

        $this->assertSame('Ativo', ProjectStatus::Active->label());
        $this->assertSame('Pausado', ProjectStatus::Paused->label());
        $this->assertSame('Concluído', ProjectStatus::Done->label());

        $this->assertTrue(ProjectStatus::Active->isOpen());
        $this->assertTrue(ProjectStatus::Paused->isOpen());
        $this->assertFalse(ProjectStatus::Done->isOpen());
    }

    #[Test]
    public function activity_type_values(): void
    {
        $expected = [
            'created', 'status_changed', 'note', 'postponed',
            'edited', 'next_step_changed', 'expired',
            'timer_started', 'timer_paused', 'timer_resumed', 'timer_stopped',
        ];

        $actual = array_map(fn ($c) => $c->value, ActivityType::cases());

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function timer_state_values(): void
    {
        $this->assertSame('idle', TimerState::Idle->value);
        $this->assertSame('running', TimerState::Running->value);
        $this->assertSame('paused', TimerState::Paused->value);
        $this->assertSame('stopped', TimerState::Stopped->value);
    }

    #[Test]
    public function notification_type_values_and_default_channels(): void
    {
        $types = [
            'daily_digest', 'task_due_soon', 'task_due_imminent', 'task_expired',
            'task_completed', 'task_estimate_exceeded', 'timer_running_long',
            'project_due_soon', 'project_overdue', 'project_ready_to_complete',
            'task_overdue_postponed',
        ];

        $actual = array_map(fn ($c) => $c->value, NotificationType::cases());
        $this->assertSame($types, $actual);

        $this->assertSame('email', NotificationType::DailyDigest->defaultChannel());
        $this->assertSame('in_app', NotificationType::TaskExpired->defaultChannel());
        $this->assertSame('email', NotificationType::TaskDueSoon->defaultChannel());
    }
}
