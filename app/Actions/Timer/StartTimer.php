<?php

declare(strict_types=1);

namespace App\Actions\Timer;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Enums\TimerState;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\RecordActivity;
use App\Services\TimerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Início (`start`) e retoma (`resume`) do timer (doc 08).
 *
 *  - `start` exige estado `idle`; `resume` exige `paused`. Chamar o endpoint
 *    errado → `422 TIMER_WRONG_STATE`. Já estar a correr nesta tarefa →
 *    `409 TIMER_ALREADY_RUNNING` (idempotência amigável, devolve a tarefa).
 *  - Se outra tarefa do utilizador estiver a correr, é **pausada
 *    automaticamente** (`auto_pause`, actividade `timer_paused`) e devolvida
 *    em `pausedTask` — há no máximo um timer por utilizador.
 *  - `todo`/`postponed` passam a `in_progress` (doc 08 regra 3; G-02: a única
 *    forma de uma adiada voltar a estar em curso é o timer).
 *  - A hora é sempre a do servidor; o cliente nunca envia timestamps.
 */
class StartTimer
{
    /**
     * Conflito quando a tarefa já tem o timer a correr (409, resposta amigável
     * com a tarefa). A mensagem vive aqui para o controller HTTP e a action
     * (chamada por outros caminhos) falarem a mesma coisa.
     */
    public const ALREADY_RUNNING_CODE = 'TIMER_ALREADY_RUNNING';

    public const ALREADY_RUNNING_MESSAGE = 'Esta tarefa já tem o timer a correr.';

    public function __construct(private readonly TimerService $timers) {}

    /**
     * @return array{task: Task, pausedTask: Task|null}
     */
    public function handle(User $user, Task $task, string $mode = 'start'): array
    {
        $mode = $mode === 'resume' ? 'resume' : 'start';

        $this->assertAllowed($task, $mode);

        // O índice único parcial (`task_time_entries_one_open_per_user`)
        // protege de corridas: se outro pedido criou a entrada primeiro, a
        // inserção falha e repetimos uma vez — na repetência a entrada
        // concorrente é fechada como `auto_pause` (doc 08 regra 1).
        $tries = 0;

        while (true) {
            try {
                return DB::transaction(fn (): array => $this->run($user, $task, $mode));
            } catch (QueryException $e) {
                if ($tries >= 1 || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $tries++;
            }
        }
    }

    /**
     * @throws DomainRuleException
     */
    private function assertAllowed(Task $task, string $mode): void
    {
        $state = $this->timers->state($task);

        // Já está a correr nesta tarefa: conflito amigável (409).
        if ($state === TimerState::Running) {
            throw new DomainRuleException(
                self::ALREADY_RUNNING_CODE,
                self::ALREADY_RUNNING_MESSAGE,
                409,
            );
        }

        // Concluída/expirada nunca aceita tempo (doc 08 regra 2), com a
        // razão exacta na mensagem — o utilizador tem de saber o que fazer.
        if (! in_array($task->status, [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::Postponed], true)) {
            throw new DomainRuleException(
                'TIMER_NOT_ALLOWED',
                $task->status === TaskStatus::Expired
                    ? 'Não é possível iniciar o tempo de uma tarefa expirada. Reative-a com um novo prazo.'
                    : 'Não é possível iniciar o tempo de uma tarefa concluída.',
                422,
            );
        }

        // `start` exige `idle`; `resume` exige `paused`.
        $expected = $mode === 'resume' ? TimerState::Paused : TimerState::Idle;

        if ($state !== $expected) {
            throw new DomainRuleException(
                'TIMER_WRONG_STATE',
                $mode === 'resume'
                    ? 'Só é possível retomar o timer de uma tarefa que está pausada.'
                    : 'Esta tarefa já tem tempo registado. Use retomar para continuar.',
                422,
            );
        }
    }

    /**
     * @return array{task: Task, pausedTask: Task|null}
     */
    private function run(User $user, Task $task, string $mode): array
    {
        $pausedTask = $this->pauseRunningTask($user, $task);

        $entry = TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $task->setRelation('activeTimeEntry', $entry);

        $attributes = [];

        if ($task->first_started_at === null) {
            $attributes['first_started_at'] = now();
        }

        $promoted = in_array($task->status, [TaskStatus::Todo, TaskStatus::Postponed], true);

        if ($promoted) {
            $attributes['status'] = TaskStatus::InProgress;
        }

        if ($attributes !== []) {
            $task->update($attributes);
        }

        if ($promoted) {
            app(RecordActivity::class)->record(
                $user,
                $task,
                ActivityType::StatusChanged,
                'Estado alterado para '.TaskStatus::InProgress->label(),
            );
        }

        app(RecordActivity::class)->record(
            $user,
            $task,
            $mode === 'resume' ? ActivityType::TimerResumed : ActivityType::TimerStarted,
            $mode === 'resume' ? 'Timer retomado' : 'Timer iniciado',
        );

        $task->setRelation('user', $user);
        $task->load('activity');

        return ['task' => $task, 'pausedTask' => $pausedTask];
    }

    /**
     * Fecha a entrada aberta de outra tarefa do mesmo utilizador, se existir
     * (motivo `auto_pause` + actividade `timer_paused` "Timer pausado
     * automaticamente"), e devolve essa tarefa para o `pausedTask`.
     */
    private function pauseRunningTask(User $user, Task $task): ?Task
    {
        // Há no máximo uma entrada aberta por utilizador (índice único
        // parcial na base de dados) — a fonte da query é o TimerService.
        $entry = $this->timers->openForUser($user);

        if (! $entry instanceof TaskTimeEntry || ! $entry->task instanceof Task) {
            return null;
        }

        // A própria tarefa já está a correr: tratada antes, como conflito.
        if ($entry->task_id === $task->id) {
            return null;
        }

        $stopped = app(CloseOpenTimer::class)->handle($entry->task, 'auto_pause');

        if ($stopped instanceof TaskTimeEntry) {
            app(RecordActivity::class)->record(
                $user,
                $entry->task,
                ActivityType::TimerPaused,
                'Timer pausado automaticamente',
            );
        }

        $entry->task->setRelation('user', $user);
        $entry->task->setRelation('activeTimeEntry', null);
        $entry->task->load('activity');

        return $entry->task;
    }

    /**
     * Violação de índice único (PostgreSQL `23505`; SQLite `UNIQUE
     * constraint failed`) — usada para detectar a corrida de dois timers.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) === '23505') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
