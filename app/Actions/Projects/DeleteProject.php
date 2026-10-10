<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\ActivityEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteProject
{
    /**
     * Apaga o projeto e tudo o que depende dele, numa transação:
     *  - a actividade das **tarefas** tem de ser apagada à mão porque
     *    `activity_entries.subject` é polymorphic (não tem foreign key);
     *  - as tarefas e as suas entradas de tempo desaparecem pelo
     *    `cascadeOnDelete` das migrations (`tasks.project_id`,
     *    `task_time_entries.task_id`);
     *  - o histórico do projeto é apagado antes do projeto.
     *
     * As remoções usam subqueries em vez de listas de ids em PHP: com ids
     * em bind parameters, um projeto com muitos milhares de tarefas
     * ultrapassaria o limite de parâmetros do PostgreSQL.
     */
    public function handle(User $user, Project $project): void
    {
        $taskType = (new Task)->getMorphClass();

        DB::transaction(function () use ($user, $project, $taskType): void {
            ActivityEntry::where('user_id', $user->id)
                ->where('subject_type', $taskType)
                ->whereIn('subject_id', $project->tasks()->select('id'))
                ->delete();

            $project->activity()->delete();

            $project->delete();
        });
    }
}
