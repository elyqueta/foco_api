<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->catchWord().' '.$this->faker->catchWord(),
            'description' => $this->faker->optional()->sentence(),
            'category' => 'professional',
            'urgency' => 'medium',
            'status' => 'active',
            'can_postpone' => true,
            'due_date' => $this->faker->optional()->date(),
            'next_step' => '',
            'color' => $this->faker->hexColor(),
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => 'done']);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'paused']);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['urgency' => 'high']);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['urgency' => 'critical']);
    }
}
