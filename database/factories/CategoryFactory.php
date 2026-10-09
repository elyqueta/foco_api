<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement(['Professional', 'Pessoal', 'Casa', 'Estudos', 'Saúde']);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'name_key' => strtolower($name),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
