<?php

declare(strict_types=1);

namespace App\Actions\Timer;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resumo de tempo (`GET /api/v1/time/summary`, doc 08).
 *
 * `from`/`to` são dias **do fuso do utilizador**, inclusive; por omissão os
 * últimos 7 dias (hoje incluído). As entradas são recortadas à janela e as que
 * atravessam a meia-noite são divididas pelo dia local — a soma nunca conta o
 * mesmo segundo duas vezes. `groupBy`:
 *  - `day`     → `[ { key: "2026-10-05", seconds } ]` (ordem cronológica);
 *  - `task`    → `[ { key: taskId, title, seconds } ]` (mais tempo primeiro);
 *  - `category`→ `[ { key: "professional", seconds } ]`.
 *
 * Todas as queries são com scoping por utilizador (regra 6). O tempo de uma
 * entrada ainda aberta conta até agora (hora do servidor).
 */
class TimeSummary
{
    /**
     * Janela máxima de um pedido (a API não pagina na v1, mas um ano chega
     * para qualquer gráfico do front).
     */
    public const MAX_DAYS = 366;

    /**
     * Tecto de entradas lidas por pedido — rede de segurança.
     */
    private const ENTRY_LIMIT = 2000;

    /**
     * @return array{groupBy: string, from: string, to: string, items: list<array<string, mixed>>, totalSeconds: int}
     */
    public function for(User $user, ?string $from, ?string $to, string $groupBy): array
    {
        $tz = new \DateTimeZone(app(UserClock::class)->timezone($user));

        $today = CarbonImmutable::now($tz)->startOfDay();

        $start = $this->day($from, $tz) ?? $today->subDays(6);
        $end = $this->day($to, $tz) ?? $today;

        // Rede de segurança (o request já valida a ordem).
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        // Janela: do início do dia `from` ao início do dia seguinte a `to`.
        $windowStart = $start;
        $windowEnd = $end->addDay();

        $entries = TaskTimeEntry::query()
            ->forUser($user->id)
            ->with('task')
            ->where('started_at', '<', $windowEnd->utc())
            ->where(function ($query) use ($windowStart): void {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', $windowStart->utc());
            })
            ->orderBy('started_at')
            ->limit(self::ENTRY_LIMIT)
            ->get();

        return $this->aggregate(
            $entries,
            $windowStart,
            $windowEnd,
            $tz,
            $groupBy,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        );
    }

    /**
     * @param  Collection<int, TaskTimeEntry>  $entries
     * @return array{groupBy: string, from: string, to: string, items: list<array<string, mixed>>, totalSeconds: int}
     */
    private function aggregate($entries, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, \DateTimeZone $tz, string $groupBy, string $from, string $to): array
    {
        /** @var array<string, int> $byDay */
        $byDay = [];
        /** @var array<string, array{title: string, seconds: int}> $byKey */
        $byKey = [];
        $total = 0;

        foreach ($entries as $entry) {
            $task = $entry->task;

            if (($groupBy === 'task' || $groupBy === 'category') && ! $task instanceof Task) {
                continue;
            }

            [$start, $end] = $this->clip($entry, $windowStart, $windowEnd, $tz);

            while ($start->lessThan($end)) {
                $dayEnd = $start->addDay()->startOfDay();
                $segmentEnd = $dayEnd->lessThan($end) ? $dayEnd : $end;

                $seconds = max(0, (int) $start->diffInSeconds($segmentEnd));

                if ($seconds > 0) {
                    $total += $seconds;

                    if ($groupBy === 'task') {
                        $key = (string) $task->id;
                        $byKey[$key]['title'] = (string) $task->title;
                        $byKey[$key]['seconds'] = ($byKey[$key]['seconds'] ?? 0) + $seconds;
                    } elseif ($groupBy === 'category') {
                        $key = (string) $task->category;
                        $byKey[$key]['seconds'] = ($byKey[$key]['seconds'] ?? 0) + $seconds;
                    } else {
                        $key = $start->format('Y-m-d');
                        $byDay[$key] = ($byDay[$key] ?? 0) + $seconds;
                    }
                }

                $start = $segmentEnd;
            }
        }

        return [
            'groupBy' => $groupBy,
            'from' => $from,
            'to' => $to,
            'items' => $this->items($groupBy, $byDay, $byKey),
            'totalSeconds' => $total,
        ];
    }

    /**
     * @param  array<string, int>  $byDay
     * @param  array<string, array{title: string, seconds: int}>  $byKey
     * @return list<array<string, mixed>>
     */
    private function items(string $groupBy, array $byDay, array $byKey): array
    {
        if ($groupBy !== 'task' && $groupBy !== 'category') {
            ksort($byDay);

            $rows = [];

            foreach ($byDay as $key => $seconds) {
                $rows[] = ['key' => (string) $key, 'seconds' => $seconds];
            }

            return $rows;
        }

        $rows = [];

        foreach ($byKey as $key => $row) {
            $rows[] = $groupBy === 'task'
                ? ['key' => $key, 'title' => $row['title'], 'seconds' => $row['seconds']]
                : ['key' => $key, 'seconds' => $row['seconds']];
        }

        // Mais tempo primeiro; a chave desempata (ordem estável).
        usort($rows, static fn (array $a, array $b): int => [$b['seconds'], $a['key']] <=> [$a['seconds'], $b['key']]);

        return $rows;
    }

    /**
     * Recorta a entrada à janela e converte para o fuso do utilizador: uma
     * entrada aberta conta até agora, uma entrada antiga é cortada no início
     * da janela.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function clip(TaskTimeEntry $entry, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, \DateTimeZone $tz): array
    {
        $start = CarbonImmutable::instance($entry->started_at)->setTimezone($tz);

        if ($start->lessThan($windowStart)) {
            $start = $windowStart;
        }

        $end = $entry->ended_at instanceof \DateTimeInterface
            ? CarbonImmutable::instance($entry->ended_at)->setTimezone($tz)
            : CarbonImmutable::now()->setTimezone($tz);

        if ($end->greaterThan($windowEnd)) {
            $end = $windowEnd;
        }

        return [$start, $end];
    }

    private function day(?string $value, \DateTimeZone $tz): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), $tz);

        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }
}
