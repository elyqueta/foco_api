<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTimeEntry;

/**
 * Fecha a entrada de tempo aberta de uma tarefa, se existir (efeitos de
 * `complete`, `postpone`, `PATCH status → todo`; doc 00 §5.5).
 *
 * O acumular de `tracked_seconds` e a actividade `timer_stopped` pertencem
 * à Fase 8; aqui fecha-se apenas a entrada de forma mecânica
 * (`ended_at` + `ended_reason`) e devolve-se a entrada fechada (ou null).
 */
class CloseOpenTimer
{
    public function handle(Task $task, string $reason): ?TaskTimeEntry
    {
        $entry = $task->activeTimeEntry;

        if (! $entry instanceof TaskTimeEntry) {
            return null;
        }

        $entry->update([
            'ended_at' => now(),
            'ended_reason' => $reason,
        ]);

        return $entry;
    }
}
