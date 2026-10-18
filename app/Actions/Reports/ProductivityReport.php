<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Relatório de Produtividade: calcula todos os dados para o relatório do
 * utilizador autenticado, num intervalo de tempo.
 *
 * Regras de cálculo:
 * - Projetos: todos os projetos do utilizador, ordenados por urgência e depois
 *   por prazo. O `progress` vem do atributo calculado do model `Project`.
 * - Tarefas: incluídas se tiverem prazo dentro do intervalo OU tiverem sido
 *   concluídas dentro do intervalo. Ordenadas por prazo e depois por prioridade
 *   (da mais crítica à mais baixa).
 * - `tasks_overdue`: não concluídas com prazo anterior a hoje (fuso do user).
 * - `tasks_due_today`: não concluídas com prazo igual a hoje (fuso do user).
 * - `tasks_pending`: não concluídas.
 * - `completion_rate`: concluídas / total em percentagem, 0 se total for 0.
 * - `due_label`: today | overdue | upcoming | none (relativo a hoje do user).
 */
class ProductivityReport
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, array $period): array
    {
        $today = $user->today();
        $todayStr = $today->format('Y-m-d');
        $startDate = $period['start_date'];
        $endDate = $period['end_date'];

        // Buscar projetos com contagem de tarefas (evita N+1).
        $projects = $user->projects()
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn ($q) => $q->where('status', TaskStatus::Done->value),
            ])
            ->get();

        // Buscar tarefas com o projeto para ordenação e projeto associado.
        $tasks = $user->tasks()
            ->with('project:id,name')
            ->get();

        // Filtrar tarefas pelo período: prazo dentro do intervalo OU concluídas dentro do intervalo.
        $filteredTasks = $tasks->filter(function ($task) use ($startDate, $endDate): bool {
            $dueDate = $task->due_date?->format('Y-m-d');
            $completedAt = $task->completed_at?->format('Y-m-d');

            $dueInRange = $dueDate !== null && $dueDate >= $startDate && $dueDate <= $endDate;
            $completedInRange = $completedAt !== null && $completedAt >= $startDate && $completedAt <= $endDate;

            return $dueInRange || $completedInRange;
        })->values();

        // Ordenar tarefas: por prazo (nulo por último) e depois por urgência (crítica primeiro).
        $urgencyOrder = [
            Urgency::Critical->value => 0,
            Urgency::High->value => 1,
            Urgency::Medium->value => 2,
            Urgency::Low->value => 3,
        ];

        $filteredTasks = $filteredTasks->sortBy(function ($task) use ($urgencyOrder): array {
            $dueDate = $task->due_date?->format('Y-m-d') ?? '9999-99-99';
            $urgency = $task->urgency?->value;
            $urgencyRank = $urgency !== null ? ($urgencyOrder[$urgency] ?? 4) : 4;

            return [$dueDate, $urgencyRank, $task->created_at];
        })->values();

        // Ordenar projetos: por urgência e depois por prazo.
        $sortedProjects = $projects->sortBy(function ($project) use ($urgencyOrder): array {
            $urgency = $project->urgency?->value;
            $urgencyRank = $urgency !== null ? ($urgencyOrder[$urgency] ?? 4) : 4;
            $dueDate = $project->due_date?->format('Y-m-d') ?? '9999-99-99';

            return [$urgencyRank, $dueDate, $project->created_at];
        })->values();

        // Calcular métricas de resumo.
        $totalTasks = $filteredTasks->count();
        $completedTasks = $filteredTasks->filter(fn ($t) => $t->status === TaskStatus::Done)->count();
        $pendingTasks = $totalTasks - $completedTasks;
        $overdueTasks = 0;
        $dueTodayTasks = 0;

        foreach ($filteredTasks as $task) {
            $dueDate = $task->due_date?->format('Y-m-d');
            $status = $task->status;

            if ($status !== TaskStatus::Done && $status !== TaskStatus::Expired) {
                if ($dueDate !== null && $dueDate < $todayStr) {
                    $overdueTasks++;
                }
                if ($dueDate !== null && $dueDate === $todayStr) {
                    $dueTodayTasks++;
                }
            }
        }

        $completionRate = $totalTasks > 0
            ? (int) round(($completedTasks / $totalTasks) * 100)
            : 0;

        $activeProjects = $sortedProjects->filter(fn ($p) => $p->status === ProjectStatus::Active)->count();

        $periodLabel = $this->periodLabel($period['type'], $period['start_date'], $period['end_date']);

        return [
            'report' => [
                'title' => 'Relatório de Produtividade',
                'period' => [
                    'type' => $period['type'],
                    'label' => $periodLabel,
                    'start_date' => $period['start_date'],
                    'end_date' => $period['end_date'],
                ],
                'generated_at' => now()->toIso8601String(),
                'timezone' => $user->timezone ?? 'UTC',
            ],
            'user' => [
                'name' => $user->name,
            ],
            'summary' => [
                'projects_total' => $sortedProjects->count(),
                'projects_active' => $activeProjects,
                'tasks_total' => $totalTasks,
                'tasks_completed' => $completedTasks,
                'tasks_pending' => $pendingTasks,
                'tasks_overdue' => $overdueTasks,
                'tasks_due_today' => $dueTodayTasks,
                'completion_rate' => $completionRate,
            ],
            'projects' => $sortedProjects->map(function ($project): array {
                $progress = $project->progress;
                $percent = is_array($progress) ? ($progress['percent'] ?? 0) : 0;

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'category' => $project->category,
                    'status' => $project->status->value,
                    'deadline' => $project->due_date?->format('Y-m-d'),
                    'urgency' => $project->urgency?->value,
                    'progress' => (int) $percent,
                    'next_step' => $project->next_step,
                ];
            })->values()->all(),
            'tasks' => $filteredTasks->map(function ($task) use ($todayStr): array {
                $dueDate = $task->due_date?->format('Y-m-d');
                $dueLabel = $this->dueLabel($dueDate, $todayStr, $task->status);

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'category' => $task->category,
                    'priority' => $task->urgency?->value,
                    'status' => $task->status->value,
                    'due_date' => $dueDate,
                    'due_label' => $dueLabel,
                    'estimated_minutes' => $task->estimate_minutes,
                    'project_id' => $task->project_id,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Devolve o rótulo do período em pt-PT.
     */
    private function periodLabel(string $type, string $startDate, string $endDate): string
    {
        $months = [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];
        $start = CarbonImmutable::parse($startDate);

        return match ($type) {
            'today' => 'Hoje',
            'this_week' => 'Esta semana',
            'last_week' => 'Semana passada',
            'this_month', 'last_month' => $months[(int) $start->month].' de '.$start->format('Y'),
            'custom' => $start->format('d/m/Y').' a '.CarbonImmutable::parse($endDate)->format('d/m/Y'),
            default => 'Hoje',
        };
    }

    /**
     * Devolve o due_label para uma tarefa.
     */
    private function dueLabel(?string $dueDate, string $todayStr, mixed $status): string
    {
        if ($dueDate === null) {
            return 'none';
        }

        if ($status === TaskStatus::Done || $status === TaskStatus::Expired) {
            return 'none';
        }

        if ($dueDate === $todayStr) {
            return 'today';
        }

        if ($dueDate < $todayStr) {
            return 'overdue';
        }

        return 'upcoming';
    }
}
