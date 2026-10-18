<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        $dueDate = $this->faker->optional(0.7)->date();

        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'title' => $this->faker->word().' '.$this->faker->word(),
            'description' => $this->faker->sentence(),
            'category' => 'professional',
            'urgency' => 'medium',
            'status' => 'todo',
            'can_postpone' => true,
            'due_date' => $dueDate,
            'due_time' => $dueDate ? $this->faker->time() : null,
            'next_step' => '',
            'estimate_minutes' => $this->faker->optional(0.6)->numberBetween(15, 480),
            'tags' => [],
            'tracked_seconds' => 0,
            'first_started_at' => null,
            'completed_at' => null,
            'expired_at' => null,
            'postponed_count' => 0,
        ];
    }

    public function withProject(): static
    {
        return $this->state(fn () => [
            'project_id' => Project::factory(),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => 'done',
            'completed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'due_date' => Carbon::now()->subDays(3)->format('Y-m-d'),
            'expired_at' => now(),
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['urgency' => 'critical']);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => 'in_progress']);
    }

    public function postponed(): static
    {
        return $this->state(fn () => ['status' => 'postponed', 'postponed_count' => 1]);
    }

    public function withDue(?string $date, ?string $time = null): static
    {
        return $this->state(fn () => [
            'due_date' => $date,
            'due_time' => $time,
        ]);
    }
}
