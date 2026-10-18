<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_two_projects_and_four_tasks(): void
    {
        User::factory()->create(['email' => 'admin@todo.ao']);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoDataSeeder']);

        $user = User::whereEmail('admin@todo.ao')->first();
        $this->assertEquals(2, $user->projects()->count());
        $this->assertEquals(4, $user->tasks()->count());
    }

    #[Test]
    public function one_task_is_in_progress_others_todo(): void
    {
        User::factory()->create(['email' => 'admin@todo.ao']);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoDataSeeder']);

        $inProgressCount = Task::where('status', TaskStatus::InProgress)->count();
        $todoCount = Task::where('status', TaskStatus::Todo)->count();

        $this->assertEquals(1, $inProgressCount);
        $this->assertEquals(3, $todoCount);
    }

    #[Test]
    public function no_task_has_open_timer(): void
    {
        User::factory()->create(['email' => 'admin@todo.ao']);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoDataSeeder']);

        $openTimers = TaskTimeEntry::whereNull('ended_at')->count();

        $this->assertEquals(0, $openTimers);
    }

    #[Test]
    public function does_not_run_if_user_already_has_data(): void
    {
        $user = User::factory()->create(['email' => 'admin@todo.ao']);
        $user->projects()->create([
            'name' => 'Existing Project',
            'category' => 'professional',
            'color' => '#6C5CE7',
        ]);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoDataSeeder']);

        $this->assertEquals(1, $user->projects()->count());
        $this->assertEquals(0, $user->tasks()->count());
    }

    #[Test]
    public function runs_when_passed_user_directly(): void
    {
        $user = User::factory()->create(['email' => 'other@todo.ao']);

        (new DemoDataSeeder)
            ->setContainer($this->app)
            ->run($user);

        $this->assertEquals(2, $user->projects()->count());
        $this->assertEquals(4, $user->tasks()->count());
    }

    #[Test]
    public function projects_have_expected_names(): void
    {
        User::factory()->create(['email' => 'admin@todo.ao']);

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoDataSeeder']);

        $names = Project::pluck('name')->sort()->values()->toArray();

        $this->assertEquals(['Loja Nerd', 'destino-mussulo'], $names);
    }
}
