<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\NotificationType;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MailPlan;
use App\Services\MailGate;
use App\Services\NotificationDispatcher;
use App\Services\UserClock;
use Carbon\CarbonImmutable;

/**
 * Lembretes de prazo (doc 10 §"Tick", passo 3). Corre no tick, por
 * utilizador e **no fuso dele**: as janelas são calculadas com a hora local
 * e comparadas com `<=`, para que um tick perdido seja recuperado no
 * seguinte (a dedupe em `notification_dispatch_log` garante um único envio).
 *
 *  - `task_due_soon` — `todo|in_progress|postponed` com prazo entre agora e
 *    agora+`due_soon_hours`. Tarefas **sem hora** usam o fim do dia (23:59)
 *    como instante do prazo na janela de 24 h, mas só são avisadas a partir
 *    das **18:00 do dia anterior** (evita um aviso às 00:05).
 *  - `task_due_imminent` — tarefas **com hora** a `due_imminent_minutes`
 *    ou menos do prazo (e ainda não passado).
 *  - `project_due_soon` — projeto `active|paused` com prazo hoje ou amanhã:
 *    a partir das `digest_hour` do próprio dia ou das 18:00 do dia anterior.
 *  - `project_overdue` — projeto `active` com prazo passado e
 *    `overdue_notified_at` em NULL: notifica e preenche a coluna.
 *  - `task_overdue_postponed` — tarefa `postponed` cuja data já passou
 *    (regra G-03: uma tarefa adiada nunca expira sozinha).
 *
 * Os canais seguem as preferências do utilizador: o in-app é enviado plano
 * a plano e o email passa pelo `MailGate` (teto diário + agregação).
 */
class SendReminders
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly UserClock $clock,
        private readonly MailGate $gate,
    ) {}

    /**
     * Corre os lembretes de um utilizador. Devolve o número de planas
     * criados (independentemente de já terem sido enviados antes — a
     * dedupe vive no dispatcher).
     */
    public function forUser(User $user): int
    {
        $plans = [
            ...$this->taskPlans($user),
            ...$this->projectPlans($user),
        ];

        if ($plans === []) {
            return 0;
        }

        foreach ($plans as $plan) {
            if (! $this->inAppActive($user, $plan->type)) {
                continue;
            }

            $this->dispatcher->dispatchChannels(
                $user,
                $plan->type,
                $plan->subject,
                $plan->dedupeKey,
                [NotificationDispatcher::CHANNEL_IN_APP],
                $plan->context,
            );
        }

        $this->gate->send($user, $plans);

        $this->markOverdueProjects($user, $plans);

        return count($plans);
    }

    /**
     * Planos de tarefas: prazo a aproximar-se, iminente e adiada em atraso.
     *
     * @return list<MailPlan>
     */
    private function taskPlans(User $user): array
    {
        $now = $this->clock->now($user);

        $tasks = $user->tasks()
            ->with('user')
            ->whereIn('status', [
                TaskStatus::Todo->value,
                TaskStatus::InProgress->value,
                TaskStatus::Postponed->value,
            ])
            ->whereNotNull('due_date')
            ->get();

        $plans = [];

        foreach ($tasks as $task) {
            $hasTime = $this->hasTime($task);

            $due = $this->dueInstant($task, $user);
            $secondsLeft = $due->getTimestamp() - $now->getTimestamp();
            $dateKey = $due->format('Y-m-d');

            // Tarefa adiada cuja data já passou (G-03): nunca expira sozinha,
            // por isso o aviso é único e independente da janela de 24 h.
            if ($task->status === TaskStatus::Postponed && $this->dueDay($task) < $now->format('Y-m-d')) {
                $plans[] = new MailPlan(
                    NotificationType::TaskOverduePostponed,
                    $task,
                    'overdue_postponed:'.$dateKey,
                );

                continue;
            }

            if ($secondsLeft <= 0) {
                // Já passou: quem trata é a expiração (tarefas abertas) ou o
                // plano acima (adiadas) — um lembrete de "a expirar" não faz
                // sentido para algo já vencido.
                continue;
            }

            if ($hasTime && $secondsLeft <= $this->imminentMinutes($user) * 60) {
                $plans[] = new MailPlan(
                    NotificationType::TaskDueImminent,
                    $task,
                    'due_imminent:'.$due->format('Y-m-d\TH:i'),
                    ['minutes' => max(1, (int) ceil($secondsLeft / 60))],
                );
            }

            if ($now->lt($this->windowStart($due, $hasTime, $this->dueSoonHours($user)))) {
                continue;
            }

            $plans[] = new MailPlan(
                NotificationType::TaskDueSoon,
                $task,
                'due_soon:'.$dateKey,
            );
        }

        return $plans;
    }

    /**
     * Planos de projetos: prazo a aproximar-se e projeto atrasado (com a
     * coluna `overdue_notified_at` gravada depois do envio).
     *
     * @return list<MailPlan>
     */
    private function projectPlans(User $user): array
    {
        $now = $this->clock->now($user);
        $today = $now->format('Y-m-d');
        $tomorrow = $now->addDay()->format('Y-m-d');

        $projects = $user->projects()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::Paused->value])
            ->whereNotNull('due_date')
            ->get();

        $plans = [];

        foreach ($projects as $project) {
            $due = $project->due_date?->format('Y-m-d');

            if ($due === null) {
                continue;
            }

            if ($due === $today && $now->hour >= $this->digestHour($user)) {
                $plans[] = new MailPlan(
                    NotificationType::ProjectDueSoon,
                    $project,
                    'project_due:'.$due,
                );
            } elseif ($due === $tomorrow && $now->hour >= 18) {
                $plans[] = new MailPlan(
                    NotificationType::ProjectDueSoon,
                    $project,
                    'project_due:'.$due,
                );
            }

            if ($due < $today && $project->status === ProjectStatus::Active && $project->overdue_notified_at === null) {
                $plans[] = new MailPlan(
                    NotificationType::ProjectOverdue,
                    $project,
                    'overdue',
                );
            }
        }

        return $plans;
    }

    /**
     * Um projeto só fica marcado como avisado quando a notificação existe
     * (in-app ou email): se o envio falhar, o tick seguinte tenta outra vez.
     *
     * @param  list<MailPlan>  $plans
     */
    private function markOverdueProjects(User $user, array $plans): void
    {
        foreach ($plans as $plan) {
            if ($plan->type !== NotificationType::ProjectOverdue || ! $plan->subject instanceof Project) {
                continue;
            }

            if (! $this->dispatcher->wasDelivered($user, $plan->type, $plan->subject, $plan->dedupeKey)) {
                continue;
            }

            $plan->subject->forceFill(['overdue_notified_at' => now()])->save();
        }
    }

    /**
     * Instante local do prazo. Sem hora, o fim do dia (23:59) — é esse o
     * valor usado na janela de 24 h do `task_due_soon` (doc 10).
     */
    private function dueInstant(Task $task, User $user): CarbonImmutable
    {
        $time = $this->hasTime($task) ? (string) $task->due_time : '23:59';

        return $this->clock->toLocal($this->dueDay($task), $time, $user);
    }

    /**
     * Início da janela de aviso do `task_due_soon`:
     *  - com hora, `due_soon_hours` antes do prazo;
     *  - **sem hora**, das 18:00 do dia anterior ao prazo — a janela de 24 h
     *    chegava às 23:59 desse dia, pelo que o aviso só saía à meia-noite
     *    ("avisar às 00:05"). Com a marca das 18:00 o lembrete sai na véspera
     *    à noite; uma preferência maior continua a poder começar mais cedo.
     */
    private function windowStart(CarbonImmutable $due, bool $hasTime, int $dueSoonHours): CarbonImmutable
    {
        $fromHours = $due->subHours($dueSoonHours);

        if ($hasTime) {
            return $fromHours;
        }

        $eveningBefore = $due->subDay()->setTime(18, 0);

        return $eveningBefore->lt($fromHours) ? $eveningBefore : $fromHours;
    }

    private function hasTime(Task $task): bool
    {
        return $task->due_time !== null && $task->due_time !== '';
    }

    private function dueDay(Task $task): string
    {
        return $task->due_date?->format('Y-m-d') ?? '';
    }

    private function dueSoonHours(User $user): int
    {
        return $this->preference($user, 'due_soon_hours', 24);
    }

    private function imminentMinutes(User $user): int
    {
        return $this->preference($user, 'due_imminent_minutes', 60);
    }

    private function digestHour(User $user): int
    {
        return $this->preference($user, 'digest_hour', 8);
    }

    /**
     * Preferência do utilizador com o valor por omissão da Fase 4; sem
     * registo (conta antiga) ou com valor inválido, o omissão.
     */
    private function preference(User $user, string $key, int $default): int
    {
        $value = $user->notificationPreference?->getAttribute($key);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return (int) $value >= 1 ? (int) $value : $default;
    }

    private function inAppActive(User $user, NotificationType $type): bool
    {
        return in_array(
            NotificationDispatcher::CHANNEL_IN_APP,
            $this->dispatcher->activeChannels($user, $type),
            true,
        );
    }
}
