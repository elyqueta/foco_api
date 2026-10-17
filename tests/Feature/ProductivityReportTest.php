<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class ProductivityReportTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/v1/exports/productivity-report?period=today')
            ->assertUnauthorized();
    }

    #[Test]
    public function it_requires_period_parameter(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/v1/exports/productivity-report')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    #[Test]
    public function it_returns_today_report(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $user->tasks()->create([
            'title' => 'Tarefa de hoje',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-17',
            'due_time' => '14:00:00',
        ]);

        $user->projects()->create([
            'name' => 'Projeto de hoje',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => ProjectStatus::Active->value,
            'due_date' => '2026-10-17',
            'next_step' => 'Revisar documento',
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'report' => [
                        'title',
                        'period' => ['type', 'label', 'start_date', 'end_date'],
                        'generated_at',
                        'timezone',
                    ],
                    'user' => ['name'],
                    'summary' => [
                        'projects_total', 'projects_active', 'tasks_total',
                        'tasks_completed', 'tasks_pending', 'tasks_overdue',
                        'tasks_due_today', 'completion_rate',
                    ],
                    'projects',
                    'tasks',
                ],
            ]);

        $response->assertJson([
            'data' => [
                'report' => [
                    'title' => 'Relatório de Produtividade',
                    'period' => [
                        'type' => 'today',
                        'start_date' => '2026-10-17',
                        'end_date' => '2026-10-17',
                    ],
                    'timezone' => 'Africa/Luanda',
                ],
                'user' => ['name' => $user->name],
            ],
        ]);
    }

    #[Test]
    public function it_returns_this_week_report(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00'); // Sábado

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=this_week');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'this_week',
                            'start_date' => '2026-10-12', // Segunda-feira
                            'end_date' => '2026-10-18',   // Domingo
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_returns_last_week_report(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00'); // Sábado

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=last_week');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'last_week',
                            'start_date' => '2026-10-05',
                            'end_date' => '2026-10-11',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_returns_this_month_report(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=this_month');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'this_month',
                            'label' => 'Outubro de 2026',
                            'start_date' => '2026-10-01',
                            'end_date' => '2026-10-31',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_returns_last_month_report(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=last_month');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'last_month',
                            'label' => 'Setembro de 2026',
                            'start_date' => '2026-09-01',
                            'end_date' => '2026-09-30',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_returns_custom_report_with_dates(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=custom&start_date=2026-10-01&end_date=2026-10-15');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'custom',
                            'start_date' => '2026-10-01',
                            'end_date' => '2026-10-15',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_rejects_custom_without_dates(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/v1/exports/productivity-report?period=custom')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function it_rejects_custom_with_end_before_start(): void
    {
        $user = $this->actingAsUser();

        $this->getJson('/api/v1/exports/productivity-report?period=custom&start_date=2026-10-15&end_date=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    #[Test]
    public function it_returns_zeros_and_empty_lists_for_user_without_data(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'summary' => [
                        'projects_total' => 0,
                        'projects_active' => 0,
                        'tasks_total' => 0,
                        'tasks_completed' => 0,
                        'tasks_pending' => 0,
                        'tasks_overdue' => 0,
                        'tasks_due_today' => 0,
                        'completion_rate' => 0,
                    ],
                    'projects' => [],
                    'tasks' => [],
                ],
            ]);
    }

    #[Test]
    public function it_does_not_return_data_from_other_users(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // Outro usuário com dados
        $otherUser = User::factory()->create(['timezone' => 'Africa/Luanda']);
        $otherUser->tasks()->create([
            'title' => 'Tarefa do outro usuário',
            'category' => 'professional',
            'urgency' => Urgency::Critical->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-17',
        ]);
        $otherUser->projects()->create([
            'name' => 'Projeto do outro usuário',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => ProjectStatus::Active->value,
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'summary' => [
                        'projects_total' => 0,
                        'tasks_total' => 0,
                    ],
                    'projects' => [],
                    'tasks' => [],
                ],
            ]);
    }

    #[Test]
    public function it_includes_tasks_due_within_period(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // Tarefa com prazo dentro do período (hoje)
        $user->tasks()->create([
            'title' => 'Tarefa dentro do período',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-17',
        ]);

        // Tarefa com prazo fora do período (amanhã)
        $user->tasks()->create([
            'title' => 'Tarefa fora do período',
            'category' => 'professional',
            'urgency' => Urgency::Low->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-18',
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJsonCount(1, 'data.tasks');
    }

    #[Test]
    public function it_includes_tasks_completed_within_period(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // Tarefa concluída hoje com prazo de ontem (fora do período de prazo, mas concluída dentro)
        $user->tasks()->create([
            'title' => 'Tarefa concluída hoje',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Done->value,
            'due_date' => '2026-10-16',
            'completed_at' => '2026-10-17 09:00:00',
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJsonCount(1, 'data.tasks');
    }

    #[Test]
    public function it_calculates_summary_metrics_correctly(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // 3 projetos: 2 ativos, 1 concluído
        $user->projects()->create([
            'name' => 'Projeto ativo 1',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => ProjectStatus::Active->value,
        ]);
        $user->projects()->create([
            'name' => 'Projeto ativo 2',
            'category' => 'personal',
            'urgency' => Urgency::Medium->value,
            'status' => ProjectStatus::Active->value,
        ]);
        $user->projects()->create([
            'name' => 'Projeto concluído',
            'category' => 'household',
            'urgency' => Urgency::Low->value,
            'status' => ProjectStatus::Done->value,
        ]);

        // Usar this_week para incluir todas as tarefas (prazo de 16 a 18 está dentro da semana 12-18)
        $user->tasks()->create([
            'title' => 'Tarefa concluída',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => TaskStatus::Done->value,
            'due_date' => '2026-10-17',
            'completed_at' => '2026-10-17 09:00:00',
        ]);
        $user->tasks()->create([
            'title' => 'Tarefa concluída 2',
            'category' => 'personal',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Done->value,
            'due_date' => '2026-10-17',
            'completed_at' => '2026-10-17 10:00:00',
        ]);
        $user->tasks()->create([
            'title' => 'Tarefa pendente',
            'category' => 'household',
            'urgency' => Urgency::Low->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-18',
        ]);
        $user->tasks()->create([
            'title' => 'Tarefa atrasada',
            'category' => 'professional',
            'urgency' => Urgency::Critical->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-16',
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=this_week');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'summary' => [
                        'projects_total' => 3,
                        'projects_active' => 2,
                        'tasks_total' => 4,
                        'tasks_completed' => 2,
                        'tasks_pending' => 2,
                        'tasks_overdue' => 1,
                        'tasks_due_today' => 0,
                        'completion_rate' => 50,
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_orders_tasks_by_due_date_then_priority(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // Tarefa com prazo depois e prioridade baixa
        $user->tasks()->create([
            'title' => 'Tarefa baixa prioridade',
            'category' => 'professional',
            'urgency' => Urgency::Low->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-18',
        ]);
        // Tarefa com prazo antes e prioridade crítica
        $user->tasks()->create([
            'title' => 'Tarefa crítica',
            'category' => 'personal',
            'urgency' => Urgency::Critical->value,
            'status' => TaskStatus::Todo->value,
            'due_date' => '2026-10-17',
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=this_week');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'tasks' => [
                        [
                            'title' => 'Tarefa crítica',
                            'priority' => 'critical',
                        ],
                        [
                            'title' => 'Tarefa baixa prioridade',
                            'priority' => 'low',
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_calculates_project_progress(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $project = $user->projects()->create([
            'name' => 'Projeto com tarefas',
            'category' => 'professional',
            'urgency' => Urgency::High->value,
            'status' => ProjectStatus::Active->value,
        ]);

        // Criar 4 tarefas, 2 concluídas = 50% progresso
        $user->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Tarefa 1 concluída',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Done->value,
        ]);
        $user->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Tarefa 2 concluída',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Done->value,
        ]);
        $user->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Tarefa 3 pendente',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Todo->value,
        ]);
        $user->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Tarefa 4 pendente',
            'category' => 'professional',
            'urgency' => Urgency::Medium->value,
            'status' => TaskStatus::Todo->value,
        ]);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=today');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'projects' => [
                        [
                            'name' => 'Projeto com tarefas',
                            'progress' => 50,
                        ],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_uses_month_parameter(): void
    {
        Carbon::setTestNow('2026-10-17T10:00:00+01:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        $response = $this->getJson('/api/v1/exports/productivity-report?period=this_month&month=2026-11');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'report' => [
                        'period' => [
                            'type' => 'this_month',
                            'start_date' => '2026-11-01',
                            'end_date' => '2026-11-30',
                            'label' => 'Novembro de 2026',
                        ],
                    ],
                ],
            ]);
    }
}
