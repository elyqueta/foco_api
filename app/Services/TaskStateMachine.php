<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;

/**
 * Máquina de estados da tarefa (doc 07 §5.2 + G-01/G-02/G-07).
 *
 * Tabela de transições permitidas **através da API** (PATCH status,
 * complete, reopen, postpone). As transições para `expired` são do sistema
 * (só `ExpireOverdueTasks`) e `postponed → in_progress` só existe pelo timer
 * (Fase 8): nenhuma das duas aparece aqui, por isso são rejeitadas quando
 * pedidas manualmente.
 *
 * As restrições de contexto (ex. `expired → todo` exige um novo `dueDate ≥
 * hoje`; `→ postponed` via PATCH exige data) são aplicadas nas ações — aqui
 * vive apenas a tabela pura de estados, que é o que o teste de tabela cobre.
 */
class TaskStateMachine
{
    /**
     * Origem => destinos permitidos pela API. Valores de `TaskStatus`.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        TaskStatus::Todo->value => [
            TaskStatus::InProgress->value,
            TaskStatus::Postponed->value,
            TaskStatus::Done->value,
        ],
        TaskStatus::InProgress->value => [
            TaskStatus::Todo->value,
            TaskStatus::Postponed->value,
            TaskStatus::Done->value,
        ],
        TaskStatus::Postponed->value => [
            TaskStatus::Done->value,
        ],
        TaskStatus::Done->value => [
            TaskStatus::Todo->value,
        ],
        TaskStatus::Expired->value => [
            TaskStatus::Done->value,
            TaskStatus::Todo->value,
        ],
    ];

    public function can(TaskStatus $from, TaskStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @throws DomainRuleException 422 INVALID_TRANSITION quando a transição
     *                             não está na tabela (inclui pedir `expired`
     *                             manualmente, G-07).
     */
    public function assertCan(TaskStatus $from, TaskStatus $to): void
    {
        if ($this->can($from, $to)) {
            return;
        }

        throw new DomainRuleException(
            'INVALID_TRANSITION',
            "Transição de estado inválida: {$from->value} → {$to->value}.",
            422,
        );
    }
}
