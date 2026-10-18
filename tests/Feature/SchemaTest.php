<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function morph_map_is_enforced(): void
    {
        $this->assertSame(Task::class, Relation::morphMap()['task']);
        $this->assertSame(Project::class, Relation::morphMap()['project']);
        $this->assertSame(ActivityEntry::class, Relation::morphMap()['activity']);
    }

    #[Test]
    public function user_has_foco_fields_and_relations(): void
    {
        $user = User::factory()->create();

        $this->assertSame('Africa/Luanda', $user->timezone);
        $this->assertSame('light', $user->theme);
        $this->assertNotNull($user->categories()->first());
        $this->assertEquals(0, $user->projects()->count());
        $this->assertEquals(0, $user->tasks()->count());
        $this->assertNotNull($user->notificationPreference()->first());
    }

    #[Test]
    public function factory_creates_valid_records(): void
    {
        $user = User::factory()->create();

        $project = $user->projects()->create([
            'name' => 'Loja Nerd',
            'category' => 'professional',
            'urgency' => 'high',
            'status' => 'active',
            'color' => '#6C5CE7',
        ]);

        $task = $user->tasks()->create([
            'title' => 'Integrar API',
            'category' => 'professional',
            'urgency' => 'critical',
            'status' => 'todo',
            'due_date' => '2026-10-05',
            'due_time' => '14:30',
            'estimate_minutes' => 90,
            'tags' => ['angular', 'api'],
        ]);

        $this->assertNotNull($project->id);
        $this->assertNotNull($task->id);
        $this->assertSame(['angular', 'api'], $task->tags);
        $this->assertNotNull($task->dueAtLocal);
        $this->assertNotNull($task->dueDateString);
        $this->assertSame('2026-10-05T14:30', $task->dueDateString);
        $this->assertIsArray($project->progress);

        ActivityEntry::create([
            'user_id' => $user->id,
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'type' => 'created',
            'message' => 'Tarefa criada',
            'at' => now(),
            'created_at' => now(),
        ]);

        ActivityEntry::create([
            'user_id' => $user->id,
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'type' => 'created',
            'message' => 'Projeto criado',
            'at' => now(),
            'created_at' => now(),
        ]);

        $this->assertCount(1, $task->fresh()->activity);
        $this->assertCount(1, $project->fresh()->activity);
    }

    #[Test]
    public function deleting_project_cascades_tasks(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create([
            'name' => 'Projeto',
            'category' => 'professional',
            'color' => '#6C5CE7',
        ]);

        $user->tasks()->create([
            'title' => 'T1',
            'category' => 'professional',
            'project_id' => $project->id,
        ]);
        $user->tasks()->create([
            'title' => 'T2',
            'category' => 'professional',
            'project_id' => $project->id,
        ]);

        $projectId = $project->id;

        $project->delete();

        $this->assertSame(0, Task::where('project_id', $projectId)->count());
    }

    #[Test]
    public function only_one_open_time_entry_per_task(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'T',
            'category' => 'professional',
        ]);

        TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subHour(),
            'created_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function only_one_open_time_entry_per_user(): void
    {
        $user = User::factory()->create();
        $task1 = $user->tasks()->create(['title' => 'T1', 'category' => 'professional']);
        $task2 = $user->tasks()->create(['title' => 'T2', 'category' => 'professional']);

        TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task1->id,
            'started_at' => now()->subHour(),
            'created_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task2->id,
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function closing_entry_allows_a_new_open_entry(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create(['title' => 'T', 'category' => 'professional']);

        $entry = TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now()->subHour(),
            'created_at' => now(),
        ]);

        $entry->update([
            'ended_at' => now(),
            'ended_reason' => 'pause',
        ]);

        // Should not throw.
        TaskTimeEntry::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertCount(2, $task->timeEntries);
    }
}
