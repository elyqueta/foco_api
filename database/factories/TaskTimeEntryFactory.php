<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TaskTimeEntry>
 */
class TaskTimeEntryFactory extends Factory
{
    protected $model = TaskTimeEntry::class;

    public function definition(): array
    {
        $startedAt = Carbon::now()->subHours(2);

        return [
            'user_id' => User::factory(),
            'task_id' => Task::factory(),
            'started_at' => $startedAt,
            'ended_at' => null,
            'ended_reason' => null,
            'created_at' => $startedAt,
        ];
    }

    public function closed(string $reason = 'pause'): static
    {
        return $this->state(function (array $attributes) use ($reason) {
            $startedAt = $attributes['started_at'] ?? Carbon::now()->subHours(2);

            return [
                'ended_at' => $startedAt->copy()->addMinutes(90),
                'ended_reason' => $reason,
            ];
        });
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'ended_at' => null,
            'ended_reason' => null,
        ]);
    }
}
