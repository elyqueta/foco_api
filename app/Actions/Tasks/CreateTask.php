<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Categories\ResolveCategory;
use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Exceptions\DomainRuleException;
use App\Models\Task;
use App\Models\User;
use App\Services\RecordActivity;
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

            app(RecordActivity::class)->record(
                user: $user,
                subject: $task,
                type: ActivityType::Created,
                message: 'Tarefa criada',
            );

            $task->setRelation('user', $user);

            return $task->load('activity');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(User $user, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2) {
            throw new DomainRuleException(
                'INVALID_TITLE',
                'O título da tarefa deve ter pelo menos 2 caracteres.',
                422,
            );
        }

        $todayLocal = app(UserClock::class)->today($user)->format('Y-m-d');

        ['date' => $dueDate, 'time' => $dueTime] = DueDate::parse($data['dueDate'] ?? null);

        // Criação com prazo no passado é bloqueada (compara só a data, ignora
        // a hora — doc 07 §6.1). No PATCH é permitido.
        if ($dueDate !== null && $dueDate < $todayLocal) {
            throw DomainRuleException::withErrors(
                'PAST_DUE_DATE',
                'Não é possível criar tarefas com prazo no passado.',
                ['dueDate' => ['Não é possível criar tarefas com prazo no passado.']],
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
                throw DomainRuleException::withErrors(
                    'PROJECT_NOT_FOUND',
                    'O projeto indicado não existe.',
                    ['projectId' => ['O projeto indicado não existe.']],
                );
            }
        } else {
            $projectId = null;
        }

        $tags = $this->normalizeTags($data['tags'] ?? []);

        $estimateMinutes = $data['estimateMinutes'] ?? $data['estimate_minutes'] ?? null;
        if ($estimateMinutes !== null) {
            $estimateMinutes = (int) $estimateMinutes;
            if ($estimateMinutes < 0) {
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

    /**
     * Tags: lista de strings não vazias, no máximo 20 (doc 07).
     *
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $clean[] = $tag;
            }
        }

        return array_slice(array_values($clean), 0, 20);
    }
}
