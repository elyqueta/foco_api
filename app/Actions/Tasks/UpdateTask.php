<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Categories\ResolveCategory;
use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\RecordActivity;
use App\Services\TaskStateMachine;
use App\Services\TimerService;
use App\Services\UserClock;
use Illuminate\Support\Facades\DB;

class UpdateTask
{
    /**
     * Atualização parcial da tarefa (doc 07). Só muda os campos enviados;
     * `status` segue a máquina de estados; `dueDate` é permitido no PATCH
     * (ao contrário da criação). Sem nenhum valor diferente responde
     * `422 NO_CHANGES` (padrão da API).
     *
     * Actividade por pedido (na ordem):
     *  - ciclo de vida (`status_changed`, `postponed` ou, na reactivação de
     *    uma `expired`, `edited` "Prazo atualizado; tarefa reativada") +
     *    `timer_stopped` quando concluir fecha um timer;
     *  - `next_step_changed` se o próximo passo mudou;
     *  - **uma** entrada `edited` "Tarefa editada" para os outros campos.
     */
    public function handle(User $user, Task $task, array $data): Task
    {
        $transition = $this->resolveTransition($user, $task, $data);

        $candidates = $this->resolveAttributes($user, $task, $data, $transition);

        $changes = $this->diff($task, $candidates);

        if ($changes === []) {
            throw DomainRuleException::noChanges();
        }

        DB::transaction(function () use ($user, $task, $changes, $transition): void {
            $stoppedEntry = null;

            if ($transition['closeReason'] !== null) {
                $stoppedEntry = app(CloseOpenTimer::class)->handle($task, $transition['closeReason']);
            }

            $task->update($changes);

            $this->recordActivity($user, $task, $changes, $transition, $stoppedEntry);
        });

        $task->setRelation('user', $user);

        return $task->load('activity');
    }

    /**
     * Descobre a transição de estado pedida (explícita via `status` ou
     * implícita na reactivação de uma `expired` por novo prazo).
     *
     * @param  array<string, mixed>  $data
     * @return array{target: TaskStatus, closeReason: string|null, completed: string|null, reactivated: bool, toPostponed: bool, dueDate: string|null, dueTime: string|null}
     */
    private function resolveTransition(User $user, Task $task, array $data): array
    {
        $today = app(UserClock::class)->today($user)->format('Y-m-d');

        $hasDue = array_key_exists('dueDate', $data);
        ['date' => $dueDate, 'time' => $dueTime] = $hasDue ? DueDate::parse($data['dueDate']) : ['date' => null, 'time' => null];

        $statusInput = array_key_exists('status', $data)
            ? TaskStatus::tryFrom(trim((string) $data['status']))
            : null;

        $base = [
            'closeReason' => null,
            'completed' => null,
            'reactivated' => false,
            'toPostponed' => false,
            'dueDate' => $dueDate,
            'dueTime' => $dueTime,
        ];

        $current = $task->status;

        // Pedir `expired` manualmente é sempre inválido (G-07).
        if ($statusInput === TaskStatus::Expired) {
            throw new DomainRuleException(
                'INVALID_TRANSITION',
                "Transição de estado inválida: {$current->value} → ".TaskStatus::Expired->value.'.',
                422,
            );
        }

        // Sem campo `status`: reactivação implícita de uma `expired` quando o
        // PATCH define um novo prazo ≥ hoje (doc §PATCH, G-01).
        if ($statusInput === null) {
            if ($current === TaskStatus::Expired && $hasDue && $dueDate !== null && $dueDate >= $today) {
                return [...$base, 'target' => TaskStatus::Todo, 'reactivated' => true, 'closeReason' => 'pause'];
            }

            return [...$base, 'target' => $current];
        }

        // Com `status` igual ao actual não há transição.
        if ($statusInput === $current) {
            return [...$base, 'target' => $current];
        }

        // Transição explícita.
        app(TaskStateMachine::class)->assertCan($current, $statusInput);

        $target = $statusInput;

        return match ($target) {
            TaskStatus::Done => [...$base, 'target' => $target, 'completed' => 'set', 'closeReason' => 'complete'],
            TaskStatus::InProgress => [...$base, 'target' => $target],
            TaskStatus::Postponed => $this->postponeTransition($data, $hasDue, $base, $target),
            TaskStatus::Todo => $this->todoTransition($current, $target, $hasDue, $dueDate, $today, $base),
            // Atingíveis aqui apenas Done/InProgress/Postponed/Todo (Expired
            // é rejeitado antes). Se algum valor novo chegar aqui, falhar de
            // forma explícita em vez de descartar a transição em silêncio.
            default => throw new DomainRuleException(
                'INVALID_TRANSITION',
                "Transição de estado inválida: {$current->value} → {$target->value}.",
                422,
            ),
        };
    }

    /**
     * `→ postponed` via PATCH exige uma nova data (usa-se o endpoint
     * `postpone`).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function postponeTransition(array $data, bool $hasDue, array $base, TaskStatus $target): array
    {
        if (! $hasDue) {
            throw new DomainRuleException(
                'POSTPONE_REQUIRES_DATE',
                'Para adiar a tarefa indique a nova data de prazo.',
                422,
            );
        }

        return [...$base, 'target' => $target, 'toPostponed' => true, 'closeReason' => 'postpone'];
    }

    /**
     * `→ todo`. De `expired` é uma reactivação e exige um novo prazo ≥ hoje
     * (G-01); caso contrário o valor actual do prazo não mudaria e a tarefa
     * continuaria em risco de reexpirar.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function todoTransition(TaskStatus $current, TaskStatus $target, bool $hasDue, ?string $dueDate, string $today, array $base): array
    {
        if ($current === TaskStatus::Expired) {
            if (! $hasDue || $dueDate === null || $dueDate < $today) {
                throw new DomainRuleException(
                    'INVALID_TRANSITION',
                    "Transição de estado inválida: {$current->value} → {$target->value} (defina uma nova data de prazo a partir de hoje).",
                    422,
                );
            }

            return [...$base, 'target' => $target, 'reactivated' => true, 'closeReason' => 'pause'];
        }

        return [...$base, 'target' => $target, 'completed' => 'clear', 'closeReason' => 'pause'];
    }

    /**
     * Campos candidatos a escrever (só os enviados), já normalizados para a
     * base de dados, incluindo os efeitos da transição de estado.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $transition
     * @return array<string, mixed>
     */
    private function resolveAttributes(User $user, Task $task, array $data, array $transition): array
    {
        $attributes = [];

        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $attributes['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = trim((string) $data['description']);
        }

        if (array_key_exists('category', $data) && trim((string) $data['category']) !== '') {
            $attributes['category'] = app(ResolveCategory::class)->canonicalName($user, (string) $data['category']);
        }

        if (array_key_exists('urgency', $data) && trim((string) $data['urgency']) !== '') {
            $urgency = Urgency::tryFrom((string) $data['urgency']);
            if ($urgency instanceof Urgency) {
                $attributes['urgency'] = $urgency;
            }
        }

        if (array_key_exists('canPostpone', $data)) {
            $attributes['can_postpone'] = (bool) $data['canPostpone'];
        }

        if (array_key_exists('nextStep', $data)) {
            $attributes['next_step'] = trim((string) $data['nextStep']);
        }

        if (array_key_exists('estimateMinutes', $data)) {
            $estimate = $data['estimateMinutes'];
            $attributes['estimate_minutes'] = ($estimate === null || $estimate === '') ? null : max(0, (int) $estimate);
        }

        if (array_key_exists('tags', $data)) {
            $attributes['tags'] = is_array($data['tags']) ? array_values($data['tags']) : [];
        }

        if (array_key_exists('projectId', $data)) {
            $attributes['project_id'] = $this->resolveProjectId($user, $data['projectId']);
        }

        // Prazo (permitido no PATCH, ao contrário da criação).
        if (array_key_exists('dueDate', $data)) {
            $attributes['due_date'] = $transition['dueDate'];
            $attributes['due_time'] = $transition['dueTime'];
        }

        // Estado + efeitos da transição.
        $target = $transition['target'];
        if ($target !== $task->status || $transition['reactivated']) {
            $attributes['status'] = $target;

            if ($transition['completed'] === 'set') {
                $attributes['completed_at'] = now();
            } elseif ($transition['completed'] === 'clear') {
                $attributes['completed_at'] = null;
            }

            if ($transition['toPostponed']) {
                $attributes['postponed_count'] = $task->postponed_count + 1;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $candidates
     * @return array<string, mixed>
     */
    private function diff(Task $task, array $candidates): array
    {
        $changes = [];

        foreach ($candidates as $key => $value) {
            if (! $this->isSame($key, $task->{$key}, $value)) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $transition
     */
    private function recordActivity(User $user, Task $task, array $changes, array $transition, ?TaskTimeEntry $stoppedEntry): void
    {
        $record = app(RecordActivity::class);

        $lifecycle = null;

        if ($transition['reactivated']) {
            $lifecycle = [ActivityType::Edited, 'Prazo atualizado; tarefa reativada'];
        } elseif ($transition['toPostponed']) {
            $lifecycle = [ActivityType::Postponed, PostponeMessage::for($transition['dueDate'], $transition['dueTime'])];
        } elseif (($changes['status'] ?? null) instanceof TaskStatus) {
            $lifecycle = [ActivityType::StatusChanged, 'Estado alterado para '.$changes['status']->label()];
        }

        if ($lifecycle !== null) {
            $record->record($user, $task, $lifecycle[0], $lifecycle[1]);
        }

        if ($stoppedEntry instanceof TaskTimeEntry) {
            $record->record($user, $task, ActivityType::TimerStopped, app(TimerService::class)->stoppedMessage($stoppedEntry));
        }

        if (array_key_exists('next_step', $changes)) {
            $record->record($user, $task, ActivityType::NextStepChanged, 'Próximo passo atualizado');
        }

        // Uma única entrada "editada" para os restantes campos. Efeitos
        // colaterais da transição (estado, contador, timestamps) e o prazo —
        // quando já foi comunicado pelo adiar/reactivação — não contam como
        // "edição" do utilizador.
        $other = array_diff(array_keys($changes), [
            'status', 'next_step', 'postponed_count', 'completed_at', 'expired_at',
        ]);

        if ($transition['toPostponed'] || $transition['reactivated']) {
            $other = array_diff($other, ['due_date', 'due_time']);
        }

        if ($other !== []) {
            $record->record($user, $task, ActivityType::Edited, 'Tarefa editada');
        }
    }

    /**
     * projectId: null/vazio limpa; um UUID tem de pertencer ao utilizador
     * (422 caso contrário — isolamento entre utilizadores).
     */
    private function resolveProjectId(User $user, mixed $projectId): ?string
    {
        if ($projectId === null || $projectId === '' || ! is_string($projectId)) {
            return null;
        }

        if (! $user->projects()->whereKey($projectId)->exists()) {
            throw DomainRuleException::withErrors(
                'PROJECT_NOT_FOUND',
                'O projeto indicado não existe.',
                ['projectId' => ['O projeto indicado não existe.']],
            );
        }

        return $projectId;
    }

    /**
     * Comparação explícita e previsível: enums pelo valor, datas pela parte
     * da data, booleanos por verdadeiro/falso, arrays por igualdade e os
     * timestamps pela parte útil.
     */
    private function isSame(string $key, mixed $current, mixed $new): bool
    {
        if ($key === 'due_date') {
            // `due_date` é um Carbon (cast `date:Y-m-d`) mas o candidato é uma
            // string "YYYY-MM-DD" vinda do DueDate::parse: comparar só pela
            // parte da data, senão o valor actual ("…00:00:00") nunca iguala o
            // candidato ("…") e um `dueDate` inalterado contaria como mudança.
            return substr((string) $this->temporalValue($current), 0, 10)
                === substr((string) $this->temporalValue($new), 0, 10);
        }

        if ($key === 'completed_at' || $key === 'expired_at') {
            return $this->temporalValue($current) === $this->temporalValue($new);
        }

        if ($key === 'can_postpone') {
            return (bool) $current === (bool) $new;
        }

        if ($key === 'tags') {
            return $current === $new;
        }

        if ($key === 'estimate_minutes' || $key === 'postponed_count') {
            return (int) $current === (int) $new;
        }

        if ($current instanceof \BackedEnum) {
            $current = $current->value;
        }

        if ($new instanceof \BackedEnum) {
            $new = $new->value;
        }

        if (is_string($current) && is_string($new)) {
            return trim($current) === trim($new);
        }

        return $current === $new;
    }

    private function temporalValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return substr(trim((string) $value), 0, 19);
    }
}
