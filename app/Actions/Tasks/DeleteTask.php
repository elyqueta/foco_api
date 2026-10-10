<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\ActivityEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTask
{
    /**
     * Apaga a tarefa e tudo o que depende dela, numa transação:
     *  - a actividade da tarefa é apagada à mão porque
     *    `activity_entries.subject` é polymorphic (não tem foreign key);
     *  - as entradas de tempo desaparecem pelo `cascadeOnDelete` declarado
     *    em `task_time_entries.task_id`.
     */
    public function handle(User $user, Task $task): void
    {
        DB::transaction(function () use ($user, $task): void {
            ActivityEntry::where('user_id', $user->id)
                ->where('subject_type', (new Task)->getMorphClass())
                ->where('subject_id', $task->id)
                ->delete();

            $task->delete();
        });
    }
}
