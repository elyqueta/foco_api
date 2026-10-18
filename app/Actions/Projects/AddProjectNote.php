<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ActivityType;
use App\Exceptions\DomainRuleException;
use App\Models\ActivityEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\RecordActivity;

class AddProjectNote
{
    /**
     * Nota do projeto → entrada de atividade `note` com o texto enviado.
     * Devolve a entrada criada (contrato do `POST /projects/{id}/notes`).
     */
    public function handle(User $user, Project $project, string $text): ActivityEntry
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
            $project,
            ActivityType::Note,
            $text,
        );
    }
}
