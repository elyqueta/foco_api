<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case DailyDigest = 'daily_digest';
    case TaskDueSoon = 'task_due_soon';
    case TaskDueImminent = 'task_due_imminent';
    case TaskExpired = 'task_expired';
    case TaskCompleted = 'task_completed';
    case TaskEstimateExceeded = 'task_estimate_exceeded';
    case TimerRunningLong = 'timer_running_long';
    case ProjectDueSoon = 'project_due_soon';
    case ProjectOverdue = 'project_overdue';
    case ProjectReadyToComplete = 'project_ready_to_complete';
    case TaskOverduePostponed = 'task_overdue_postponed';

    public function label(): string
    {
        return match ($this) {
            self::DailyDigest => 'Resumo diário',
            self::TaskDueSoon => 'Prazo aproxima-se',
            self::TaskDueImminent => 'Prazo iminente',
            self::TaskExpired => 'Tarefa expirada',
            self::TaskCompleted => 'Tarefa concluída',
            self::TaskEstimateExceeded => 'Estimativa ultrapassada',
            self::TimerRunningLong => 'Timer a correr há muito',
            self::ProjectDueSoon => 'Prazo do projeto',
            self::ProjectOverdue => 'Projeto atrasado',
            self::ProjectReadyToComplete => 'Projeto pronto',
            self::TaskOverduePostponed => 'Tarefa adiada atrasada',
        };
    }

    public function defaultChannel(): string
    {
        return match ($this) {
            self::DailyDigest => 'email',
            self::TaskDueSoon => 'email',
            self::TaskDueImminent => 'email',
            self::TaskExpired => 'in_app',
            self::TaskCompleted => 'in_app',
            self::TaskEstimateExceeded => 'in_app',
            self::TimerRunningLong => 'in_app',
            self::ProjectDueSoon => 'email',
            self::ProjectOverdue => 'email',
            self::ProjectReadyToComplete => 'in_app',
            self::TaskOverduePostponed => 'in_app',
        };
    }
}
