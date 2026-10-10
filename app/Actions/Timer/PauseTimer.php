<?php

declare(strict_types=1);

namespace App\Actions\Timer;

use App\Enums\ActivityType;
use App\Enums\TimerState;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\CloseOpenTimer;
use App\Services\RecordActivity;
use App\Services\TimerService;
use Illuminate\Support\Facades\DB;

/**
 * Pausa do timer (doc 08 regra 4): fecha a entrada aberta (motivo `pause`),
 * acumula os segundos em `tracked_seconds` e mantém a tarefa `in_progress`
 * (o estado da tarefa não muda). Sem entrada aberta → `422
 * TIMER_NOT_RUNNING`.
 */
class PauseTimer
{
    public function __construct(private readonly TimerService $timers) {}

    public function handle(User $user, Task $task): Task
    {
        if ($this->timers->state($task) !== TimerState::Running) {
            throw new DomainRuleException(
                'TIMER_NOT_RUNNING',
                'O timer desta tarefa não está a correr.',
                422,
            );
        }

        $task = DB::transaction(function () use ($user, $task): Task {
            $stoppedEntry = app(CloseOpenTimer::class)->handle($task, 'pause');

            // Entrada com < 1 s é descartada: nada houve para registar.
            if ($stoppedEntry instanceof TaskTimeEntry) {
                app(RecordActivity::class)->record(
                    $user,
                    $task,
                    ActivityType::TimerPaused,
                    'Timer pausado',
                );
            }

            $task->setRelation('user', $user);

            return $task->load('activity');
        });

        return $task;
    }
}
