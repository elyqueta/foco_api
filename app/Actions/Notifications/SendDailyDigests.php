<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Actions\Timer\TimeSummary;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MailPlan;
use App\Services\MailGate;
use App\Services\NotificationDispatcher;
use App\Services\TimerService;
use App\Services\UserClock;
use Carbon\CarbonImmutable;

/**
 * Digest diário (doc 10 §"Digest diário"). Corre no tick, por utilizador e
 * na hora **local** dele: entre `digest_hour` e `digest_hour + 6` envia um
 * resumo com contagens e lista (máx. 10 por secção) do que precisa de
 * atenção. Nada a reportar → não envia (e não fecha o dia: se o utilizador
 * criar tarefas mais tarde, o digest ainda pode sair dentro da janela).
 *
 * Secções: **Para hoje** (prazo hoje, abertas), **Atrasadas** (prazo
 * anterior a hoje, abertas), **Críticas/Altas** pendentes, **Expiradas
 * ontem** e **Tempo registado ontem**.
 *
 * O envio é único por dia local: a chave `digest:{data local}` no
 * `notification_dispatch_log` faz a dedupe (o lock do tick serializa os
 * disparos sem assunto). O email passa pelo `MailGate` (teto diário e
 * agregação anti-spam).
 */
class SendDailyDigests
{
    /**
     * Janela de envio a partir da hora do digest: um tick perdido não perde
     * o digest, mas o resumo da manhã não é enviado à noite.
     */
    public const WINDOW_HOURS = 6;

    /**
     * Máximo de itens por secção (o email não é uma listagem infinita).
     */
    private const ITEM_LIMIT = 10;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly MailGate $gate,
        private readonly UserClock $clock,
        private readonly TimerService $timers,
    ) {}

    /**
     * Corre o digest do utilizador. Devolve `true` se havia algo a reportar
     * e o digest foi enviado (ou já existia de hoje).
     */
    public function forUser(User $user): bool
    {
        $now = $this->clock->now($user);
        $digestHour = $this->digestHour($user);

        if ($now->hour < $digestHour || $now->hour >= $digestHour + self::WINDOW_HOURS) {
            return false;
        }

        $sections = array_values(array_filter(
            $this->sections($user, $now),
            static fn (array $section): bool => $section['items'] !== [],
        ));

        if ($sections === []) {
            return false;
        }

        $date = $now->format('Y-m-d');

        $context = [
            'summary' => $this->summary($sections),
            'sections' => $sections,
        ];

        if ($this->inAppActive($user)) {
            $this->dispatcher->dispatchChannels(
                $user,
                NotificationType::DailyDigest,
                null,
                'digest:'.$date,
                [NotificationDispatcher::CHANNEL_IN_APP],
                $context,
            );
        }

        $this->gate->send($user, [
            new MailPlan(NotificationType::DailyDigest, null, 'digest:'.$date, $context),
        ]);

        return true;
    }

    /**
     * Secções com conteúdo (etiqueta PT, itens e valor do resumo).
     *
     * @return list<array{label: string, items: list<string>}>
     */
    private function sections(User $user, CarbonImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $yesterday = $now->subDay()->format('Y-m-d');

        return [
            $this->section('Para hoje', $this->titles(
                $user->tasks()
                    ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Expired->value])
                    ->whereDate('due_date', $today)
                    ->orderBy('urgency')
                    ->orderByRaw('CASE WHEN due_time IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('due_time')
                    ->limit(self::ITEM_LIMIT)
                    ->get(),
            )),
            $this->section('Atrasadas', $this->titles(
                $user->tasks()
                    ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Expired->value])
                    ->whereDate('due_date', '<', $today)
                    ->orderBy('urgency')
                    ->orderBy('due_date')
                    ->limit(self::ITEM_LIMIT)
                    ->get(),
            )),
            $this->section('Críticas/altas pendentes', $this->titles(
                $user->tasks()
                    ->whereIn('status', [TaskStatus::Todo->value, TaskStatus::InProgress->value, TaskStatus::Postponed->value])
                    ->whereIn('urgency', [Urgency::Critical->value, Urgency::High->value])
                    ->orderBy('urgency')
                    ->limit(self::ITEM_LIMIT)
                    ->get(),
            )),
            $this->section('Expiradas ontem', $this->titles($this->expiredYesterday($user, $now))),
            [
                'label' => 'Tempo registado ontem',
                'items' => $this->timeItems($user, $yesterday),
            ],
        ];
    }

    /**
     * @param  array<int, Task>  $tasks
     * @return list<string>
     */
    private function titles($tasks): array
    {
        $titles = [];

        foreach ($tasks as $task) {
            $title = (string) $task->title;

            if ($task->due_time !== null && $task->due_time !== '') {
                $title .= ' ('.substr((string) $task->due_time, 0, 5).')';
            }

            $titles[] = $title;
        }

        return $titles;
    }

    /**
     * Tarefas que passaram a `expired` durante o dia local de ontem (a
     * coluna é `timestamptz`, por isso os limites vêm em UTC).
     *
     * @return array<int, Task>
     */
    private function expiredYesterday(User $user, CarbonImmutable $now): array
    {
        return $user->tasks()
            ->where('status', TaskStatus::Expired->value)
            ->where('expired_at', '>=', $now->subDay()->startOfDay()->utc())
            ->where('expired_at', '<', $now->startOfDay()->utc())
            ->orderBy('expired_at')
            ->limit(self::ITEM_LIMIT)
            ->get()
            ->all();
    }

    /**
     * Tempo registado no dia local de ontem (entradas recortadas à janela:
     * a que atravessa a meia-noite é dividida e nunca conta o mesmo segundo
     * duas vezes — mesma regra do `TimeSummary`).
     *
     * @return list<string>
     */
    private function timeItems(User $user, string $yesterday): array
    {
        $summary = app(TimeSummary::class)->for(
            $user,
            $yesterday,
            $yesterday,
            'day',
        );

        $seconds = 0;

        foreach ($summary['items'] as $item) {
            if ($item['key'] === $yesterday) {
                $seconds = (int) $item['seconds'];
            }
        }

        return $seconds > 0 ? [$this->timers->format($seconds)] : [];
    }

    /**
     * Resumo em texto (corpo da notificação in-app): contagens por secção,
     * separadas por `·`.
     *
     * @param  list<array{label: string, items: list<string>}>  $sections
     */
    private function summary(array $sections): string
    {
        $parts = [];

        foreach ($sections as $section) {
            $parts[] = $section['label'] === 'Tempo registado ontem'
                ? $section['label'].': '.$section['items'][0]
                : $section['label'].': '.count($section['items']);
        }

        return implode(' · ', $parts).'.';
    }

    /**
     * @param  list<string>  $items
     * @return array{label: string, items: list<string>}
     */
    private function section(string $label, array $items): array
    {
        return ['label' => $label, 'items' => $items];
    }

    private function digestHour(User $user): int
    {
        $value = $user->notificationPreference?->getAttribute('digest_hour');

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $hour = (int) $value;

            if ($hour >= 0 && $hour <= 23) {
                return $hour;
            }
        }

        return 8;
    }

    private function inAppActive(User $user): bool
    {
        return in_array(
            NotificationDispatcher::CHANNEL_IN_APP,
            $this->dispatcher->activeChannels($user, NotificationType::DailyDigest),
            true,
        );
    }
}
