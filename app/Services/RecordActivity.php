<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Serviço único de registo de atividade (projetos e tarefas).
 *
 * Centraliza a criação de entradas em `activity_entries`: quem precisa de
 * registar atividade chama este serviço em vez de escrever na tabela —
 * garante o `subject_type` do morph map, o `at` e o `created_at`.
 */
class RecordActivity
{
    public function record(
        User $user,
        Model $subject,
        ActivityType $type,
        string $message,
        ?\DateTimeInterface $at = null,
    ): ActivityEntry {
        $at = $at ?? now();

        return ActivityEntry::create([
            'user_id' => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'message' => $message,
            'at' => $at,
            'created_at' => $at,
        ]);
    }
}
