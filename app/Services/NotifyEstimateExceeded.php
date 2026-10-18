<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Task;
use App\Models\User;

/**
 * `task_estimate_exceeded` (doc 09): o tempo registado numa tarefa atingiu
 * ou ultrapassou a estimativa (`estimate_minutes`). Disparado ao **pausar** e
 * ao **concluir** (doc 09 §"Disparos síncronos") pela mesma fonte, para as
 * duas ações partilharem a regra.
 *
 * A dedupe `estimate:{minutos}` dá uma notificação por valor de estimativa:
 * aumentar a estimativa e voltar a ultrapassá-la gera um aviso novo
 * legítimo, mas repetir o pause não repete o aviso.
 */
class NotifyEstimateExceeded
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(User $user, Task $task): bool
    {
        $estimate = $task->estimate_minutes;

        if (! is_int($estimate) || $estimate <= 0) {
            return false;
        }

        $tracked = (int) $task->tracked_seconds;

        if ($tracked < $estimate * 60) {
            return false;
        }

        return $this->dispatcher->dispatch(
            $user,
            NotificationType::TaskEstimateExceeded,
            $task,
            'estimate:'.$estimate,
            [
                'trackedSeconds' => $tracked,
                'estimateMinutes' => $estimate,
            ],
        );
    }
}
