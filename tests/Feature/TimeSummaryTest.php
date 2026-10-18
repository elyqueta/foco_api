<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class TimeSummaryTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function it_defaults_to_the_last_seven_days_grouped_by_day(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $this->entry($user, '2026-10-04T08:00:00', '2026-10-04T09:30:00'); // limite inferior da janela
        $this->entry($user, '2026-10-09T14:00:00', '2026-10-09T15:00:00'); // ontem: 1 h
        $this->entry($user, '2026-10-10T08:00:00', '2026-10-10T09:10:00'); // hoje: 1 h 10

        $this->getJson('/api/v1/time/summary')
            ->assertOk()
            ->assertJson([
                'groupBy' => 'day',
                'from' => '2026-10-04',
                'to' => '2026-10-10',
                'items' => [
                    ['key' => '2026-10-04', 'seconds' => 5400],
                    ['key' => '2026-10-09', 'seconds' => 3600],
                    ['key' => '2026-10-10', 'seconds' => 4200],
                ],
                'totalSeconds' => 13200,
            ]);
    }

    #[Test]
    public function it_splits_entries_that_cross_midnight_by_local_day(): void
    {
        // 01:00 UTC = 02:00 em África/Luanda (UTC+1).
        Carbon::setTestNow('2026-10-06T01:00:00');

        $user = $this->actingAsUser(['timezone' => 'Africa/Luanda']);

        // 23:30 → 00:30 locais: meia hora em cada dia.
        $this->entry($user, '2026-10-05T22:30:00', '2026-10-05T23:30:00');

        $this->getJson('/api/v1/time/summary?from=2026-10-05&to=2026-10-06')
            ->assertOk()
            ->assertJson([
                'items' => [
                    ['key' => '2026-10-05', 'seconds' => 1800],
                    ['key' => '2026-10-06', 'seconds' => 1800],
                ],
                'totalSeconds' => 3600,
            ]);
    }

    #[Test]
    public function the_midnight_split_follows_the_users_timezone(): void
    {
        Carbon::setTestNow('2026-10-06T01:00:00');

        $user = $this->actingAsUser(['timezone' => 'UTC']);

        // Os mesmos instantes, sem deslocamento: a entrada inteira cai no
        // mesmo dia local.
        $this->entry($user, '2026-10-05T22:30:00', '2026-10-05T23:30:00');

        $this->getJson('/api/v1/time/summary?from=2026-10-05&to=2026-10-06')
            ->assertOk()
            ->assertJson([
                'items' => [
                    ['key' => '2026-10-05', 'seconds' => 3600],
                ],
                'totalSeconds' => 3600,
            ]);
    }

    #[Test]
    public function it_counts_the_time_of_a_running_entry_until_now(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $task = $this->makeTask($user);

        TaskTimeEntry::factory()->open()->create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'started_at' => '2026-10-10T11:00:00',
            'created_at' => '2026-10-10T11:00:00',
        ]);

        $this->getJson('/api/v1/time/summary')
            ->assertOk()
            ->assertJson([
                'items' => [['key' => '2026-10-10', 'seconds' => 3600]],
                'totalSeconds' => 3600,
            ]);
    }

    #[Test]
    public function it_groups_by_task_and_category(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $professional = $this->makeTask($user, ['title' => 'Integrar API', 'category' => 'professional']);
        $personal = $this->makeTask($user, ['title' => 'Comprar prenda', 'category' => 'personal']);
        $otherProfessional = $this->makeTask($user, ['title' => 'Rever contracto', 'category' => 'professional']);

        $this->entry($user, '2026-10-09T10:00:00', '2026-10-09T11:00:00', $professional->id);   // 1 h
        $this->entry($user, '2026-10-09T12:00:00', '2026-10-09T12:30:00', $personal->id);       // 30 min
        $this->entry($user, '2026-10-09T13:00:00', '2026-10-09T14:30:00', $otherProfessional->id); // 1 h 30

        $this->getJson('/api/v1/time/summary?from=2026-10-09&to=2026-10-10&groupBy=task')
            ->assertOk()
            ->assertJson([
                'groupBy' => 'task',
                'items' => [
                    ['key' => $otherProfessional->id, 'title' => 'Rever contracto', 'seconds' => 5400],
                    ['key' => $professional->id, 'title' => 'Integrar API', 'seconds' => 3600],
                    ['key' => $personal->id, 'title' => 'Comprar prenda', 'seconds' => 1800],
                ],
                'totalSeconds' => 10800,
            ]);

        $this->getJson('/api/v1/time/summary?from=2026-10-09&to=2026-10-10&groupBy=category')
            ->assertOk()
            ->assertJson([
                'groupBy' => 'category',
                'items' => [
                    ['key' => 'professional', 'seconds' => 9000],
                    ['key' => 'personal', 'seconds' => 1800],
                ],
                'totalSeconds' => 10800,
            ]);
    }

    #[Test]
    public function it_excludes_time_outside_the_window(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $this->entry($user, '2026-10-01T10:00:00', '2026-10-01T11:00:00'); // antigo
        $this->entry($user, '2026-10-08T10:00:00', '2026-10-08T11:00:00'); // no limite inferior
        $this->entry($user, '2026-10-09T22:00:00', '2026-10-09T23:00:00');

        // Janela 2026-10-08 → 2026-10-09 (inclusive).
        $this->getJson('/api/v1/time/summary?from=2026-10-08&to=2026-10-09')
            ->assertOk()
            ->assertJson([
                'items' => [
                    ['key' => '2026-10-08', 'seconds' => 3600],
                    ['key' => '2026-10-09', 'seconds' => 3600],
                ],
                'totalSeconds' => 7200,
            ]);
    }

    #[Test]
    public function it_only_counts_the_time_of_the_authenticated_user(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        $this->entry(User::factory()->create(), '2026-10-10T10:00:00', '2026-10-10T11:00:00');
        $this->entry($user, '2026-10-10T10:00:00', '2026-10-10T10:30:00');

        $this->getJson('/api/v1/time/summary?from=2026-10-10&to=2026-10-10')
            ->assertOk()
            ->assertJson(['items' => [['key' => '2026-10-10', 'seconds' => 1800]], 'totalSeconds' => 1800]);
    }

    #[Test]
    public function it_rejects_invalid_filters(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/time/summary?from=2026-13-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->getJson('/api/v1/time/summary?from=2026-02-30')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->getJson('/api/v1/time/summary?groupBy=week')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['groupBy']);

        $this->getJson('/api/v1/time/summary?from=2026-10-06&to=2026-10-05')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->getJson('/api/v1/time/summary?from=2025-01-01&to=2026-10-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to'])
            ->assertJsonPath('errors.to.0', 'O período pedido não pode ser superior a 366 dias.');
    }

    #[Test]
    public function it_accepts_a_one_year_window(): void
    {
        Carbon::setTestNow('2026-10-10T12:00:00');

        $user = $this->actingAsUser();

        // Um ano exacto ainda é aceite; a partir daí o request rejeita.
        $this->getJson('/api/v1/time/summary?from=2025-10-10&to=2026-10-10')
            ->assertOk()
            ->assertJson(['from' => '2025-10-10', 'to' => '2026-10-10', 'totalSeconds' => 0]);
    }

    /**
     * Entrada de tempo fechada de um utilizador.
     */
    private function entry(User $user, string $startedAt, string $endedAt, ?string $taskId = null): TaskTimeEntry
    {
        $taskId ??= $this->makeTask($user)->id;

        return TaskTimeEntry::factory()->create([
            'user_id' => $user->id,
            'task_id' => $taskId,
            'started_at' => Carbon::parse($startedAt),
            'ended_at' => Carbon::parse($endedAt),
            'ended_reason' => 'pause',
            'created_at' => Carbon::parse($startedAt),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeTask(User $user, array $attributes = []): Task
    {
        return Task::factory()->create($attributes + ['user_id' => $user->id]);
    }
}
