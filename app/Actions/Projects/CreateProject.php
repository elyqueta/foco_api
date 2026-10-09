<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\ActivityLog;
use App\Enums\ActivityType;
use App\Enums\ProjectStatus;
use App\Enums\Urgency;
use App\Exceptions\DomainRuleException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function handle(User $user, array $data): Project
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($user, $data): Project {
            $project = Project::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'urgency' => $data['urgency'],
                'status' => $data['status'],
                'can_postpone' => $data['can_postpone'],
                'due_date' => $data['due_date'],
                'next_step' => $data['next_step'],
                'color' => $data['color'],
            ]);

            app(ActivityLog::class)->record(
                user: $user,
                subject: $project,
                type: ActivityType::Created,
                message: 'Projeto criado',
            );

            return $project->load('activity');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $title = trim((string) ($data['name'] ?? ''));
        if (strlen($title) < 2) {
            throw new DomainRuleException(
                'INVALID_TITLE',
                'O nome do projeto deve ter pelo menos 2 caracteres.',
                422,
            );
        }

        $category = trim((string) ($data['category'] ?? 'professional'));
        if ($category === '') {
            $category = 'professional';
        }

        $urgency = $data['urgency'] ?? 'medium';
        if (! $urgency instanceof Urgency) {
            $urgency = Urgency::tryFrom((string) $urgency) ?? Urgency::Medium;
        }

        $status = $data['status'] ?? 'active';
        if (! $status instanceof ProjectStatus) {
            $status = ProjectStatus::tryFrom((string) $status) ?? ProjectStatus::Active;
        }

        $dueDate = $data['dueDate'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            $dueDate = substr($dueDate, 0, 10);
        } else {
            $dueDate = null;
        }

        $nextStep = trim((string) ($data['nextStep'] ?? ''));

        return [
            'name' => $title,
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => $category,
            'urgency' => $urgency,
            'status' => $status,
            'can_postpone' => (bool) ($data['canPostpone'] ?? true),
            'due_date' => $dueDate,
            'next_step' => $nextStep,
            'color' => trim((string) ($data['color'] ?? '#6C5CE7')),
        ];
    }
}
