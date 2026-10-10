<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Exceptions\DomainRuleException;
use App\Models\ActivityEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\RecordActivity;

class AddTaskNote
{
    /**
     * Nota da tarefa → entrada de atividade `note` com o texto enviado.
     * Devolve a entrada criada (contrato do `POST /tasks/{id}/notes`).
     */
    public function handle(User $user, Task $task, string $text): ActivityEntry
    {
        $text = trim($text);

        if ($text === '') {
            throw new DomainRuleException(
                'EMPTY_NOTE',
                'A nota não pode estar vazia.',
                422,
            );
        }

        return app(RecordActivity::class)->record(
            $user,
            $task,
            ActivityType::Note,
            $text,
        );
    }
}
