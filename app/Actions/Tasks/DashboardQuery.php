<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TimerService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Query do dashboard (doc 11 §"Dashboard"). Corre a expiração preguiçosa
 * antes de calcular. Tudo no fuso do utilizador.
 *
 * Resposta:
 *   today        - tarefas abertas com prazo hoje
 *   pending      - tarefas abertas sem prazo ou com prazo noutro dia
 *   urgent       - tarefas críticas/altas abertas (ordem: urgência, prazo asc, nulls por último)
 *   nextSteps    - tarefas/projetos com nextStep (máx. 6)
 *   statsByCategory - todas as categorias do utilizador com total/done/percent
 *   completedThisWeek - tarefas concluídas nos últimos 7 dias
 *   focusTask    - 1.ª tarefa de urgent (modo foco)
 *   activeTimer  - tarefa com timer a correr
 *   unreadNotifications - contagem de notificações não lidas
 *   serverNow    - instante do servidor em UTC
 */
class DashboardQuery
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        app(ExpireOverdueTasks::class)->forUser($user);

        $now = $user->nowLocal();
        $today = $user->today();
        $todayStr = $today->format('Y-m-d');

        $tasks = $user->tasks()
            ->with('activeTimeEntry')
            ->get();

        $openTasks = $tasks->filter(function (Task $t): bool {
            return $t->status->isOpen();
        });

        $todayTasks = $openTasks->filter(function (Task $t): bool {
            return $t->due_date !== null && $t->due_date->format('Y-m-d') === $todayStr;
        });
        $pendingTasks = $openTasks->filter(function (Task $t): bool {
            return ! ($t->due_date !== null && $t->due_date->format('Y-m-d') === $todayStr);
        });

        $urgentTasks = $openTasks->filter(function (Task $t): bool {
            return $t->urgency !== null && in_array($t->urgency->value, [Urgency::Critical->value, Urgency::High->value], true);
        });
        $urgentTasks = $urgentTasks->sortBy(function (Task $t): array {
            return [
                $t->urgency->order(),
                $t->due_date?->format('Y-m-d') ?? '9999-99-99',
                $t->created_at,
            ];
        });

        $nextSteps = $this->nextSteps($tasks, $user->projects());

        $statsByCategory = $this->statsByCategory($user, $tasks);

        $weekAgo = $now->subDays(7)->format('Y-m-d');
        $completedThisWeek = (int) $tasks->filter(function (Task $t): bool {
            return $t->status === TaskStatus::Done && $t->completed_at !== null && $t->completed_at->format('Y-m-d') >= $weekAgo;
        })->count();

        $focusTask = $urgentTasks->first();
        $activeTimer = $tasks->first(function (Task $t): bool {
            return app(TimerService::class)->state($t)->value === 'running';
        });
        $unreadNotifications = (int) $user->notifications()->whereNull('read_at')->count();

        return [
            'today' => $todayTasks->values()->all(),
            'pending' => $pendingTasks->values()->all(),
            'urgent' => $urgentTasks->values()->all(),
            'nextSteps' => $nextSteps,
            'statsByCategory' => $statsByCategory,
            'completedThisWeek' => $completedThisWeek,
            'focusTask' => $focusTask,
            'activeTimer' => $activeTimer,
            'unreadNotifications' => $unreadNotifications,
            'serverNow' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, Project>  $projects
     * @return list<array{kind: string, id: string, title: string, nextStep: string, urgency: string|null}>
     */
    private function nextSteps(Collection $tasks, Collection $projects): array
    {
        $items = [];

        foreach ($tasks as $task) {
            if ($task->next_step !== null && $task->next_step !== '') {
                $items[] = [
                    'kind' => 'task',
                    'id' => $task->id,
                    'title' => $task->title,
                    'nextStep' => $task->next_step,
                    'urgency' => $task->urgency?->value,
                ];
            }
        }

        foreach ($projects as $project) {
            if ($project->next_step !== null && $project->next_step !== '') {
                $items[] = [
                    'kind' => 'project',
                    'id' => $project->id,
                    'title' => $project->name,
                    'nextStep' => $project->next_step,
                    'urgency' => $project->urgency?->value,
                ];
            }
        }

        // Ordenar por urgência (crítica primeiro, depois alta, média, baixa) e depois por título.
        $urgencyOrder = [
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
        ];

        usort($items, function (array $a, array $b): int {
            $orderA = $a['urgency'] !== null ? ($urgencyOrder[$a['urgency']] ?? 4) : 4;
            $orderB = $b['urgency'] !== null ? ($urgencyOrder[$b['urgency']] ?? 4) : 4;

            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return strcmp($a['title'], $b['title']);
        });

        return array_slice($items, 0, 6);
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<string, array{total: int, done: int, percent: int}>
     */
    private function statsByCategory(User $user, Collection $tasks): array
    {
        $stats = [];

        foreach ($user->categories as $category) {
            $name = (string) $category->name;
            $categoryTasks = $tasks->filter(function (Task $t): bool {
                return $t->category === $name;
            });

            $total = $categoryTasks->count();
            $done = $categoryTasks->filter(function (Task $t): bool {
                return $t->status === TaskStatus::Done;
            })->count();
            $percent = $total === 0 ? 0 : (int) round(($done / $total) * 100);

            $stats[$name] = [
                'total' => $total,
                'done' => $done,
                'percent' => $percent,
            ];
        }

        return $stats;
    }
}
