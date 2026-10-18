<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Enums\TimerState;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Estado e totais do timer de uma tarefa (doc 08). A API é a única fonte da
 * verdade do tempo: o cliente nunca envia timestamps — tudo é calculado a
 * partir de `task_time_entries` + `tasks.tracked_seconds` com a hora do
 * servidor (`now()` em UTC).
 *
 * `state(Task)` (doc 08):
 *  - `running` — existe entrada com `ended_at` null;
 *  - `paused`  — sem entrada aberta, com tempo registado e tarefa aberta;
 *  - `stopped` — tarefa `done`/`expired` com tempo registado;
 *  - `idle`    — sem tempo registado nem entrada aberta.
 */
class TimerService
{
    /**
     * Entrada aberta do utilizador (a que tem o timer a correr), se existir.
     * Fonte única da regra "há no máximo um timer a correr por utilizador" —
     * usada pelo `GET /timer/active`, pela pausa automática do `StartTimer`,
     * pela higiene do tick e pela notificação de timer longo.
     */
    public function openForUser(User $user): ?TaskTimeEntry
    {
        $entry = TaskTimeEntry::query()
            ->forUser($user->id)
            ->open()
            ->with('task')
            ->first();

        return $entry instanceof TaskTimeEntry ? $entry : null;
    }

    /**
     * Entrada de tempo aberta da tarefa (`ended_at` null), se existir. Acesso
     * pela relação: quando ela está carregada (listas com
     * `with('activeTimeEntry')`) não faz query extra — sem N+1.
     */
    public function open(Task $task): ?TaskTimeEntry
    {
        $entry = $task->activeTimeEntry;

        return $entry instanceof TaskTimeEntry ? $entry : null;
    }

    public function state(Task $task): TimerState
    {
        if ($this->open($task) instanceof TaskTimeEntry) {
            return TimerState::Running;
        }

        if ((int) $task->tracked_seconds > 0) {
            return $task->status === TaskStatus::Done || $task->status === TaskStatus::Expired
                ? TimerState::Stopped
                : TimerState::Paused;
        }

        return TimerState::Idle;
    }

    /**
     * Total de segundos da tarefa **incluindo** a entrada a decorrer
     * (`serverNow - runningSince`): o cliente só precisa de somar o tempo
     * passado desde a resposta (doc 08, bloco `timer`).
     */
    public function trackedSeconds(Task $task, ?CarbonImmutable $now = null): int
    {
        $total = (int) $task->tracked_seconds;

        $open = $this->open($task);

        if ($open instanceof TaskTimeEntry) {
            $total += $this->secondsOf($open, $now);
        }

        return $total;
    }

    /**
     * Segundos de uma entrada: `max(0, fim - início)`. Uma entrada aberta
     * conta até agora (hora do servidor).
     */
    public function secondsOf(TaskTimeEntry $entry, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $end = $entry->ended_at instanceof \DateTimeInterface
            ? CarbonImmutable::instance($entry->ended_at)
            : $now;

        $start = CarbonImmutable::instance($entry->started_at);

        return max(0, (int) $start->diffInSeconds($end));
    }

    /**
     * Formato PT do total acumulado: `1h 30m`, `45m` (mensagens de atividade
     * `timer_stopped` e notificações de estimativa).
     */
    public function format(int $seconds): string
    {
        $hours = intdiv(max(0, $seconds), 3600);
        $minutes = intdiv(max(0, $seconds) % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    /**
     * Mensagem PT da atividade `timer_stopped` (doc 08 regra 5): o tempo que
     * a entrada fechada acabou por registar.
     */
    public function stoppedMessage(TaskTimeEntry $entry): string
    {
        return 'Tempo registado: '.$this->format($this->secondsOf($entry));
    }
}
