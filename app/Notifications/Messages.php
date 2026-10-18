<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TimerService;
use App\Services\UserClock;
use App\Support\PtDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Textos PT das notificações (doc 09 §"Textos"): títulos e corpos por tipo,
 * com as datas em `pt-PT` ("hoje"/"amanhã" quando aplicável, no fuso do
 * utilizador) e os tempos no formato `Xh Ym` do timer.
 *
 * Fonte única do conteúdo in-app (e, na Fase 10, do assunto/corpo dos
 * emails): quem cria uma notificação passa apenas o tipo, o assunto e o
 * contexto — o texto nunca é espalhado pelas Actions.
 */
class Messages
{
    public function __construct(
        private readonly UserClock $clock,
        private readonly TimerService $timers,
    ) {}

    /**
     * Conteúdo de uma notificação: `title`/`body` PT e o identificador do
     * assunto (`taskId` ou `projectId`, contrato) **no mesmo array** — é
     * este o valor gravado em `notifications.data`.
     *
     * @param  array<string, mixed>  $context  extras do disparo (ex.
     *                                         `trackedSeconds`,
     *                                         `estimateMinutes`,
     *                                         `minutes`, `summary`,
     *                                         `sections`, `title`)
     * @return array{title: string, body: string, taskId?: string, projectId?: string}
     */
    public function make(User $user, NotificationType $type, ?Model $subject, array $context = []): array
    {
        return [
            'title' => $this->title($type, $context),
            'body' => $this->body($user, $type, $subject, $context),
            ...$this->data($subject),
        ];
    }

    /**
     * Título do tipo. Um `title` no contexto sobrepõe o do tipo — usado pelo
     * email agregado do tick (doc 10), que tem de se distinguir do digest.
     *
     * @param  array<string, mixed>  $context
     */
    private function title(NotificationType $type, array $context): string
    {
        $override = $context['title'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        return $this->typeTitle($type);
    }

    private function typeTitle(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::DailyDigest => 'O teu dia',
            NotificationType::TaskDueSoon => 'Tarefa a expirar',
            NotificationType::TaskDueImminent => 'Prazo daqui a pouco',
            NotificationType::TaskExpired => 'Tarefa expirada',
            NotificationType::TaskCompleted => 'Tarefa concluída 🎉',
            NotificationType::TaskEstimateExceeded => 'Tempo estimado ultrapassado',
            NotificationType::TimerRunningLong => 'Timer ainda a correr',
            NotificationType::ProjectDueSoon => 'Projeto a expirar',
            NotificationType::ProjectOverdue => 'Projeto atrasado',
            NotificationType::ProjectReadyToComplete => 'Projeto pronto a concluir',
            NotificationType::TaskOverduePostponed => 'Tarefa adiada em atraso',
            NotificationType::Welcome => 'Bem-vindo ao Foco',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function body(User $user, NotificationType $type, ?Model $subject, array $context): string
    {
        return match ($type) {
            NotificationType::DailyDigest => $this->digest($context),
            NotificationType::TaskDueSoon => $this->taskDueSoon($user, $subject),
            NotificationType::TaskDueImminent => $this->taskDueImminent($user, $subject, $context),
            NotificationType::TaskExpired => $this->taskExpired($subject, $context),
            NotificationType::TaskCompleted => $this->taskCompleted($subject, $context),
            NotificationType::TaskEstimateExceeded => $this->taskEstimateExceeded($subject, $context),
            NotificationType::TimerRunningLong => $this->timerRunningLong($subject, $context),
            NotificationType::ProjectDueSoon => $this->projectDueSoon($user, $subject),
            NotificationType::ProjectOverdue => $this->projectOverdue($subject, $context),
            NotificationType::ProjectReadyToComplete => $this->projectReadyToComplete($subject),
            NotificationType::TaskOverduePostponed => $this->taskOverduePostponed($subject),
            NotificationType::Welcome => $this->welcome($context),
        };
    }

    /**
     * "«{título}» expira {hoje/amanhã/data} {às HH:mm}."
     */
    private function taskDueSoon(User $user, ?Model $subject): string
    {
        $due = $subject instanceof Task ? $subject->dueAtLocal : null;

        if (! $due instanceof CarbonImmutable) {
            return "«{$this->name($subject)}» está perto do prazo.";
        }

        $label = PtDate::dayLabel($due, $this->clock->today($user));

        return $this->sentence("«{$this->name($subject)}» expira {$label}", $this->timeOf($subject));
    }

    /**
     * "«{título}» expira às {HH:mm} (em {N} min)."
     */
    private function taskDueImminent(User $user, ?Model $subject, array $context): string
    {
        $due = $subject instanceof Task ? $subject->dueAtLocal : null;
        $time = $this->timeOf($subject);

        if (! $due instanceof CarbonImmutable || $time === '') {
            return "«{$this->name($subject)}» expira dentro de momentos.";
        }

        $minutes = $context['minutes'] ?? null;
        $minutes = is_int($minutes) || is_numeric($minutes)
            ? max(1, (int) $minutes)
            : max(1, (int) round(($due->getTimestamp() - $this->clock->now($user)->getTimestamp()) / 60));

        return "«{$this->name($subject)}» expira às {$time} (em {$minutes} min).";
    }

    /**
     * "«{título}» passou do prazo ({data})."
     *
     * @param  array<string, mixed>  $context
     */
    private function taskExpired(?Model $subject, array $context): string
    {
        return "«{$this->name($subject)}» passou do prazo ({$this->referenceDate($subject, $context)}).";
    }

    /**
     * "Concluíste «{título}»{ — tempo registado: Xh Ym}."
     *
     * @param  array<string, mixed>  $context
     */
    private function taskCompleted(?Model $subject, array $context): string
    {
        $sentence = "Concluíste «{$this->name($subject)}»";
        $seconds = $this->intOrNull($context['trackedSeconds'] ?? $subject?->getAttribute('tracked_seconds')) ?? 0;

        if ($seconds > 0) {
            $sentence .= ' — tempo registado: '.$this->timers->format($seconds);
        }

        return $sentence.'.';
    }

    /**
     * "«{título}»: {Xh Ym} registados para uma estimativa de {E} min."
     *
     * @param  array<string, mixed>  $context
     */
    private function taskEstimateExceeded(?Model $subject, array $context): string
    {
        $seconds = $this->intOrNull($context['trackedSeconds'] ?? $subject?->getAttribute('tracked_seconds')) ?? 0;
        $minutes = $this->intOrNull($context['estimateMinutes'] ?? $subject?->getAttribute('estimate_minutes')) ?? 0;

        return "«{$this->name($subject)}»: {$this->timers->format($seconds)} registados para uma estimativa de {$minutes} min.";
    }

    /**
     * "O tempo de «{título}» está a correr há {Xh Ym}. Queres pausar?"
     *
     * @param  array<string, mixed>  $context
     */
    private function timerRunningLong(?Model $subject, array $context): string
    {
        $seconds = $this->intOrNull($context['trackedSeconds'] ?? $subject?->getAttribute('tracked_seconds')) ?? 0;

        return "O tempo de «{$this->name($subject)}» está a correr há {$this->timers->format($seconds)}. Queres pausar?";
    }

    /**
     * "«{projeto}» tem prazo {hoje/amanhã/data}."
     */
    private function projectDueSoon(User $user, ?Model $subject): string
    {
        if (! $subject instanceof Project || $subject->due_date === null) {
            return "«{$this->name($subject)}» está perto do prazo.";
        }

        $due = CarbonImmutable::instance($subject->due_date)->setTimezone($this->clock->timezone($user));

        return "«{$this->name($subject)}» tem prazo ".PtDate::dayLabel($due, $this->clock->today($user)).'.';
    }

    /**
     * "«{projeto}» passou do prazo ({data})."
     *
     * @param  array<string, mixed>  $context
     */
    private function projectOverdue(?Model $subject, array $context): string
    {
        return "«{$this->name($subject)}» passou do prazo ({$this->referenceDate($subject, $context)}).";
    }

    /**
     * "Todas as tarefas de «{projeto}» estão concluídas. Marcar o projeto
     * como concluído?"
     */
    private function projectReadyToComplete(?Model $subject): string
    {
        return "Todas as tarefas de «{$this->name($subject)}» estão concluídas. Marcar o projeto como concluído?";
    }

    /**
     * "«{título}» foi adiada para {data} e já passou."
     */
    private function taskOverduePostponed(?Model $subject): string
    {
        $due = $subject instanceof Task ? $subject->dueAtLocal : null;

        if (! $due instanceof CarbonImmutable) {
            return "«{$this->name($subject)}» foi adiada e já passou do prazo.";
        }

        $sentence = "«{$this->name($subject)}» foi adiada para ".PtDate::dayMonthYear($due);
        $time = $this->timeOf($subject);

        if ($time !== '') {
            $sentence .= ' às '.$time;
        }

        return $sentence.' e já passou.';
    }

    /**
     * Resumo diário: o texto é montado por quem dispara (Fase 10), com as
     * contagens e as listas do dia. Sem contexto, uma linha genérica.
     *
     * @param  array<string, mixed>  $context
     */
    private function digest(array $context): string
    {
        $summary = $context['summary'] ?? null;

        return is_string($summary) && trim($summary) !== ''
            ? trim($summary)
            : 'Aqui está o resumo do teu dia no Foco.';
    }

    /**
     * Nome do assunto nas mensagens (título da tarefa, nome do projeto).
     */
    private function name(?Model $subject): string
    {
        return match (true) {
            $subject instanceof Task => (string) $subject->title,
            $subject instanceof Project => (string) $subject->name,
            default => '',
        };
    }

    /**
     * Identificador do assunto no `data` da notificação (contrato:
     * `{ taskId?, projectId? }`).
     *
     * @return array<string, string>
     */
    private function data(?Model $subject): array
    {
        return match (true) {
            $subject instanceof Task => ['taskId' => (string) $subject->id],
            $subject instanceof Project => ['projectId' => (string) $subject->id],
            default => [],
        };
    }

    /**
     * Hora local do prazo (`HH:mm`), vazia quando o prazo não tem hora.
     */
    private function timeOf(?Model $subject): string
    {
        if (! $subject instanceof Task) {
            return '';
        }

        return PtDate::time($subject->due_time);
    }

    /**
     * Data de referência das mensagens de atraso: o próprio prazo quando
     * existe, senão a data indicada no contexto.
     *
     * @param  array<string, mixed>  $context
     */
    private function referenceDate(?Model $subject, array $context): string
    {
        $due = $subject instanceof Task ? $subject->due_date : ($subject instanceof Project ? $subject->due_date : null);

        if ($due !== null) {
            return PtDate::dayMonthYear(CarbonImmutable::instance($due));
        }

        $reference = $context['referenceAt'] ?? null;

        if ($reference instanceof DateTimeInterface) {
            return PtDate::dayMonthYear(CarbonImmutable::instance($reference));
        }

        if (is_string($reference) && trim($reference) !== '') {
            try {
                return PtDate::dayMonthYear(CarbonImmutable::parse($reference));
            } catch (Exception) {
                return '';
            }
        }

        return '';
    }

    /**
     * Junta " {às HH:mm}." a uma frase, apenas quando há hora.
     */
    private function sentence(string $sentence, string $time): string
    {
        return $time === ''
            ? $sentence.'.'
            : $sentence.' às '.$time.'.';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }

    /**
     * "Bem-vindo ao Foco, {nome}. Aqui tens o teu espaço de foco."
     *
     * @param  array<string, mixed>  $context
     */
    private function welcome(array $context): string
    {
        $name = $context['name'] ?? '';

        if ($name !== '') {
            return "Bem-vindo ao Foco, {$name}. Aqui tens o teu espaço de foco para organizar tarefas e projetos.";
        }

        return 'Bem-vindo ao Foco. Aqui tens o teu espaço de foco para organizar tarefas e projetos.';
    }
}
