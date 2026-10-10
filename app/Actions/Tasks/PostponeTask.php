<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\RecordActivity;
use App\Services\TaskStateMachine;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostponeTask
{
    /**
     * Adia a tarefa (doc 07). Só a partir de `todo`/`in_progress`. Exige
     * `canPostpone` e um novo prazo **≥ hoje** (G-08) — se for hoje, a hora
     * (quando existir) tem de estar no futuro. Fecha um timer aberto
     * (motivo `postpone`), incrementa `postponed_count`, muda o estado para
     * `postponed` e regista a actividade `postponed`.
     */
    public function handle(User $user, Task $task, array $data): Task
    {
        app(TaskStateMachine::class)->assertCan($task->status, TaskStatus::Postponed);

        if (! $task->can_postpone) {
            throw new DomainRuleException(
                'TASK_CANNOT_POSTPONE',
                'Esta tarefa não pode ser adiada.',
                422,
            );
        }

        ['date' => $dueDate, 'time' => $dueTime] = DueDate::parse($data['dueDate'] ?? null);

        if ($dueDate === null) {
            throw DomainRuleException::withErrors(
                'PAST_DUE_DATE',
                'Indique a nova data de prazo.',
                ['dueDate' => ['Indique a nova data de prazo.']],
            );
        }

        $this->assertDueIsFuture($user, $dueDate, $dueTime);

        DB::transaction(function () use ($user, $task, $dueDate, $dueTime): void {
            app(CloseOpenTimer::class)->handle($task, 'postpone');

            $task->update([
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'status' => TaskStatus::Postponed,
                'postponed_count' => $task->postponed_count + 1,
            ]);

            app(RecordActivity::class)->record(
                $user,
                $task,
                ActivityType::Postponed,
                PostponeMessage::for($dueDate, $dueTime),
            );
        });

        $task->setRelation('user', $user);

        return $task->load('activity');
    }

    /**
     * A nova data tem de ser ≥ hoje no fuso do utilizador; sendo hoje, a
     * hora (se existir) não pode já ter passado (G-08).
     */
    private function assertDueIsFuture(User $user, string $date, ?string $time): void
    {
        $clock = app(UserClock::class);
        $today = $clock->today($user)->format('Y-m-d');

        if ($date < $today) {
            $this->rejectPast();
        }

        if ($date === $today && $time !== null && $time !== '') {
            $now = $clock->now($user);

            [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

            $due = CarbonImmutable::create(
                $now->year,
                $now->month,
                $now->day,
                $hour,
                $minute,
                $second,
                $now->timezone,
            );

            if ($due->lessThanOrEqualTo($now)) {
                $this->rejectPast();
            }
        }
    }

    private function rejectPast(): never
    {
        throw DomainRuleException::withErrors(
            'PAST_DUE_DATE',
            'A nova data de prazo não pode estar no passado.',
            ['dueDate' => ['A nova data de prazo não pode estar no passado.']],
        );
    }
}
