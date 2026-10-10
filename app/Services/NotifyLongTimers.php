<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notificação `timer_running_long` (doc 08 regra 10): um timer aberto há
 * `timer_long_hours` ou mais (preferência do utilizador, 4 h por omissão)
 * gera uma notificação in-app. **Nunca pausa sozinho** — apenas avisa.
 *
 * Idempotente: **uma notificação por entrada**, garantida pelo registo em
 * `notification_dispatch_log` (índice único) com a chave
 * `timerlong:{entry_id}` — a mesma dedupe key da Fase 9, para que o
 * `NotificationDispatcher` não a volte a enviar quando passar a ser o dono
 * destes disparos. Respeita as preferências do utilizador (canal `in_app`).
 */
class NotifyLongTimers
{
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

            if (! $this->preferences($user)->channelEnabled(NotificationType::TimerRunningLong->value, 'in_app')) {
                continue;
            }

            if ($this->dispatch($user, $task, $entry, $seconds)) {
                $notified++;
            }
        }

        return $notified;
    }

    /**
     * Escreve notificação + registo de dedupe **na mesma transação**: se a
     * notificação falhar, o registo não fica e o tick volta a tentar.
     */
    private function dispatch(User $user, Task $task, TaskTimeEntry $entry, int $seconds): bool
    {
        try {
            return DB::transaction(function () use ($user, $task, $entry, $seconds): bool {
                $log = NotificationDispatchLog::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'type' => NotificationType::TimerRunningLong->value,
                        'channel' => 'in_app',
                        'subject_id' => $task->id,
                        'dedupe_key' => 'timerlong:'.$entry->id,
                    ],
                    [
                        'subject_type' => 'task',
                        'status' => 'sent',
                        'sent_at' => now(),
                    ],
                );

                // Já notificado noutra execução: nada a fazer.
                if (! $log->wasRecentlyCreated) {
                    return false;
                }

                $user->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => NotificationType::TimerRunningLong->value,
                    'data' => [
                        'title' => 'Timer ainda a correr',
                        'body' => "O tempo de «{$task->title}» está a correr há ".app(TimerService::class)->format($seconds).'. Queres pausar?',
                        'taskId' => $task->id,
                    ],
                ]);

                return true;
            });
        } catch (QueryException) {
            // Corrida entre dois ticks: outro já registou este envio.
            return false;
        }
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
