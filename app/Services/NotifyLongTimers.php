<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;

/**
 * Notificação `timer_running_long` (doc 08 regra 10): um timer aberto há
 * `timer_long_hours` ou mais (preferência do utilizador, 4 h por omissão)
 * gera uma notificação in-app. **Nunca pausa sozinho** — apenas avisa.
 *
 * O envio em si é do `NotificationDispatcher` (doc 09): é ele que resolve os
 * canais activos, garante a dedupe e faz o retry. A chave
 * `timerlong:{entry_id}` é a mesma desde a Fase 8, para que uma notificação
 * criada pelo comando não volte a ser enviada pelo dispatcher.
 */
class NotifyLongTimers
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(?int $userId = null): int
    {
        // `notificationPreference` vem carregado: sem isto cada entrada aberta
        // faria uma query extra à procura das preferências do utilizador
        // (N+1 dentro do tick que corre para todos os utilizadores).
        $query = TaskTimeEntry::query()->open()->with('task.user.notificationPreference');

        if ($userId !== null) {
            $query->forUser($userId);
        }

        $notified = 0;

        foreach ($query->cursor() as $entry) {
            $task = $entry->task;

            if (! $task instanceof Task || ! $task->user instanceof User) {
                continue;
            }

            $user = $task->user;

            $seconds = app(TimerService::class)->secondsOf($entry);

            if (! $this->isLong($user, $seconds)) {
                continue;
            }

            if ($this->dispatcher->dispatch(
                $user,
                NotificationType::TimerRunningLong,
                $task,
                'timerlong:'.$entry->id,
                ['trackedSeconds' => $seconds],
            )) {
                $notified++;
            }
        }

        return $notified;
    }

    private function isLong(User $user, int $seconds): bool
    {
        $hours = $this->preferences($user)->timer_long_hours;

        if (! is_int($hours) || $hours < 1) {
            $hours = (int) NotificationPreference::defaultAttributes()['timer_long_hours'];
        }

        return $seconds >= $hours * 3600;
    }

    /**
     * Preferências do utilizador; sem registo (conta antiga), os defaults.
     */
    private function preferences(User $user): NotificationPreference
    {
        $preferences = $user->notificationPreference;

        if ($preferences instanceof NotificationPreference) {
            return $preferences;
        }

        $preferences = new NotificationPreference(NotificationPreference::defaultAttributes());
        $preferences->user_id = $user->id;

        return $preferences;
    }
}
