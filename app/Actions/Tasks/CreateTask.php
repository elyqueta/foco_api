<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\ActivityLog;
use App\Actions\Categories\ResolveCategory;
use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\User;
use App\Services\UserClock;
use Illuminate\Support\Facades\DB;

class CreateTask
{
    public function handle(User $user, array $data): Task
    {
        $data = $this->normalize($user, $data);

        return DB::transaction(function () use ($user, $data): Task {
            $task = Task::create([
                'user_id' => $user->id,
                'project_id' => $data['project_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'urgency' => $data['urgency'],
                'status' => $data['status'],
                'can_postpone' => $data['can_postpone'],
                'due_date' => $data['due_date'],
                'due_time' => $data['due_time'],
                'next_step' => $data['next_step'],
                'estimate_minutes' => $data['estimate_minutes'],
                'tags' => $data['tags'],
                'postponed_count' => 0,
                'tracked_seconds' => 0,
            ]);

            app(ActivityLog::class)->record(
                user: $user,
                subject: $task,
                type: ActivityType::Created,
                message: 'Tarefa criada',
            );

            return $task->load('activity');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(User $user, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if (strlen($title) < 2) {
            throw new DomainRuleException(
                'INVALID_TITLE',
                'O título da tarefa deve ter pelo menos 2 caracteres.',
                422,
            );
        }

        $todayLocal = app(UserClock::class)->today($user)->format('Y-m-d');

        $dueDateRaw = $data['dueDate'] ?? null;
        $dueDate = null;
        $dueTime = null;

        if (is_string($dueDateRaw) && $dueDateRaw !== '') {
            $dueDateRaw = trim($dueDateRaw);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dueDateRaw, $m)) {
                $dueDate = $dueDateRaw;
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/', $dueDateRaw, $m)) {
                $dueDate = $m[1].'-'.$m[2].'-'.$m[3];
                $dueTime = $m[4].':'.$m[5].':00';
            }
        }

        if ($dueDate !== null && $dueDate < $todayLocal) {
            throw new DomainRuleException(
                'PAST_DUE_DATE',
                'A data de prazo não pode estar no passado.',
                422,
            );
        }

        $category = app(ResolveCategory::class)->canonicalName($user, (string) ($data['category'] ?? ''));

        $urgency = $data['urgency'] ?? 'medium';
        if (! $urgency instanceof Urgency) {
            $urgency = Urgency::tryFrom((string) $urgency) ?? Urgency::Medium;
        }

        $status = $data['status'] ?? 'todo';
        if (! $status instanceof TaskStatus) {
            $status = TaskStatus::tryFrom((string) $status) ?? TaskStatus::Todo;
        }

        $projectId = $data['projectId'] ?? $data['project_id'] ?? null;
        if (is_string($projectId) && $projectId !== '') {
            if (! $user->projects()->whereKey($projectId)->exists()) {
                throw new DomainRuleException(
                    'PROJECT_NOT_FOUND',
                    'Projeto não encontrado.',
                    404,
                );
            }
        } else {
            $projectId = null;
        }

        $tags = $data['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }

        $estimateMinutes = $data['estimateMinutes'] ?? $data['estimate_minutes'] ?? null;
        if ($estimateMinutes !== null) {
            $estimateMinutes = (int) $estimateMinutes;
            if ($estimateMinutes <= 0) {
                $estimateMinutes = null;
            }
        }

        return [
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => $category,
            'urgency' => $urgency,
            'status' => $status,
            'can_postpone' => (bool) ($data['canPostpone'] ?? $data['can_postpone'] ?? true),
            'due_date' => $dueDate,
            'due_time' => $dueTime,
            'next_step' => trim((string) ($data['nextStep'] ?? $data['next_step'] ?? '')),
            'estimate_minutes' => $estimateMinutes,
            'tags' => $tags,
            'project_id' => $projectId,
        ];
    }
}
