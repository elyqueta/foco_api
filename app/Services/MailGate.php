<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MailPlan;

/**
 * Política de email do tick (doc 10 §10.4): teto diário e agregação
 * anti-spam. O `NotificationDispatcher` continua a ser o único que envia e
 * regista — aqui decide-se apenas **quanto** se envia num tick.
 *
 *  - **Teto diário** (`MAIL_DAILY_LIMIT`, contado no log com
 *    `channel=email`/`status=sent` no dia UTC): ao atingi-lo, cada plano
 *    fica `skipped` com motivo `daily_limit` e a notificação in-app
 *    mantém-se (é essa a que o utilizador vê na app).
 *  - **Agregação:** com mais de `AGGREGATE_AFTER` planos no mesmo tick,
 *    envia-se **um** email agregado ("Tens N tarefas a precisar de
 *    atenção", com a lista) e cada plano fica `skipped` com motivo
 *    `batched`.
 */
class MailGate
{
    /**
     * Acima deste número de emails individuais no mesmo tick, agrega.
     */
    public const AGGREGATE_AFTER = 5;

    public const REASON_DAILY_LIMIT = 'daily_limit';

    public const REASON_BATCHED = 'batched';

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly UserClock $clock,
    ) {}

    /**
     * Envia os emails dos planos do utilizador, aplicando teto e agregação.
     * Apenas os planos cujo tipo tem o canal email activo nas preferências
     * entram — os restantes nunca são tocados.
     *
     * @param  list<MailPlan>  $plans
     */
    public function send(User $user, array $plans): void
    {
        $plans = array_values(array_filter(
            $plans,
            fn (MailPlan $plan): bool => in_array(
                NotificationDispatcher::CHANNEL_EMAIL,
                $this->dispatcher->activeChannels($user, $plan->type),
                true,
            ),
        ));

        if ($plans === []) {
            return;
        }

        $remaining = $this->remainingToday($user);

        if ($remaining <= 0) {
            foreach ($plans as $plan) {
                $this->skip($user, $plan, self::REASON_DAILY_LIMIT);
            }

            return;
        }

        if (count($plans) > self::AGGREGATE_AFTER) {
            $this->aggregate($user, $plans);

            return;
        }

        foreach ($plans as $plan) {
            if ($remaining <= 0) {
                $this->skip($user, $plan, self::REASON_DAILY_LIMIT);

                continue;
            }

            if ($this->sendOne($user, $plan)) {
                $remaining--;
            }
        }
    }

    /**
     * Emails que o utilizador ainda pode receber hoje (teto diário menos os
     * já enviados no dia UTC).
     */
    private function remainingToday(User $user): int
    {
        $limit = (int) config('services.scheduler.mail_daily_limit', 90);

        return max(0, $limit - $this->dispatcher->emailsSentToday($user));
    }

    /**
     * @param  list<MailPlan>  $plans
     */
    private function sendOne(User $user, MailPlan $plan): bool
    {
        return $this->dispatcher->dispatchChannels(
            $user,
            $plan->type,
            $plan->subject,
            $plan->dedupeKey,
            [NotificationDispatcher::CHANNEL_EMAIL],
            $plan->context,
        );
    }

    private function skip(User $user, MailPlan $plan, string $reason): void
    {
        $this->dispatcher->dispatchChannels(
            $user,
            $plan->type,
            $plan->subject,
            $plan->dedupeKey,
            [NotificationDispatcher::CHANNEL_EMAIL],
            $plan->context,
            $reason,
        );
    }

    /**
     * Email agregado: um único email com a lista do que precisa de atenção.
     * A chave `batched:{data local}` garante um agregado por dia — o conteúdo
     * é o estado actual, pelo quais é legítimo reenviá-lo no dia seguinte.
     *
     * @param  list<MailPlan>  $plans
     */
    private function aggregate(User $user, array $plans): void
    {
        $date = $this->clock->now($user)->format('Y-m-d');

        $this->dispatcher->dispatchChannels(
            $user,
            NotificationType::DailyDigest,
            null,
            'batched:'.$date,
            [NotificationDispatcher::CHANNEL_EMAIL],
            [
                'title' => 'Tarefas a precisar de atenção',
                'summary' => 'Tens '.count($plans).' tarefas a precisar de atenção:',
                'sections' => [[
                    'label' => 'A precisar de atenção',
                    'items' => array_map(
                        fn (MailPlan $plan): string => $this->describe($plan),
                        $plans,
                    ),
                ]],
            ],
        );

        // Cada notificação continua a existir individualmente (in-app já
        // enviada pelo `SendReminders`): só o canal email fica `skipped`.
        foreach ($plans as $plan) {
            $this->skip($user, $plan, self::REASON_BATCHED);
        }
    }

    /**
     * Linha curta da lista do email agregado: nome do assunto + o que o
     * tipo significa em PT.
     */
    private function describe(MailPlan $plan): string
    {
        $name = match (true) {
            $plan->subject instanceof Task => (string) $plan->subject->title,
            $plan->subject instanceof Project => (string) $plan->subject->name,
            default => '',
        };

        $hint = match ($plan->type) {
            NotificationType::TaskDueSoon => 'a expirar',
            NotificationType::TaskDueImminent => 'expira já',
            NotificationType::TaskOverduePostponed => 'adiada em atraso',
            NotificationType::ProjectDueSoon => 'projeto a expirar',
            NotificationType::ProjectOverdue => 'projeto atrasado',
            default => 'precisa de atenção',
        };

        if ($name === '') {
            return ucfirst($hint);
        }

        return "«{$name}» — {$hint}";
    }
}
