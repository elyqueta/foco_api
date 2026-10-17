<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pesquisa global (doc 11 §"Pesquisa global"): substitui o
 * `SearchDropdownComponent`. Devolve `{ projects, tasks }`, máx. 10 itens no
 * total (projetos primeiro), pesquisa case-insensitive em título/descrição/
 * nome do projeto/tags.
 *
 * `q` vazio → listas vazias.
 */
class SearchController extends Controller
{
    public const MAX_RESULTS = 10;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $q = trim((string) $request->input('q', ''));

        if ($q === '') {
            return response()->json([
                'projects' => [],
                'tasks' => [],
            ]);
        }

        $like = '%'.mb_strtolower($q).'%';

        $projects = $user->projects()
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(description) LIKE ?', [$like])
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'tasksCount' => $p->tasks->count(),
            ])
            ->values()
            ->all();

        $remaining = self::MAX_RESULTS - count($projects);

        $tasks = [];

        if ($remaining > 0) {
            $tasks = $user->tasks()
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                ->orWhereHas('project', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->with('project')
                ->orderBy('title')
                ->limit($remaining)
                ->get()
                ->map(fn (Task $t): array => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'dueDate' => $t->due_date?->format('Y-m-d'),
                    'projectName' => $t->project?->name,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'projects' => $projects,
            'tasks' => $tasks,
        ]);
    }
}
