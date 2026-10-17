<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\ActivityType;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\Category;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\RecordActivity;
use App\Services\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Importação de dados (doc 11 §"Importação"): aditiva, nunca apaga, gera
 * sempre novos UUIDs, ignora status/ids recebidos (projetos → `active`,
 * tarefas → `todo`).
 *
 * Validação manual por campo, todos os erros reportados; se existir qualquer
 * erro, nada é importado (transação + rollback).
 *
 * Resposta de sucesso: `201 { ok:true, projectsCreated: int, tasksCreated: int }`.
 */
class ImportData
{
    /**
     * Máximo de itens (projetos + tarefas) por pedido.
     */
    public const MAX_ITEMS = 500;

    /**
     * Tamanho máximo do corpo (bytes).
     */
    public const MAX_BODY_BYTES = 1024 * 1024;

    /**
     * @return array{ok: bool, projectsCreated: int, tasksCreated: int, errors?: list<string>}
     *
     * @throws Throwable
     */
    public function import(User $user, array $body): array
    {
        $projects = $this->normalizeProjects($body);
        $tasks = $this->normalizeTasks($body);

        if (count($projects) + count($tasks) > self::MAX_ITEMS) {
            return [
                'ok' => false,
                'projectsCreated' => 0,
                'tasksCreated' => 0,
                'errors' => ['Limite de '.self::MAX_ITEMS.' itens por pedido excedido.'],
            ];
        }

        $errors = [];

        // Validar projetos.
        $validProjects = [];
        foreach ($projects as $index => $project) {
            $projectErrors = $this->validateProject($project, $index, $user);
            $errors = array_merge($errors, $projectErrors);
            if ($projectErrors === []) {
                $validProjects[] = $project;
            }
        }

        // Validar tarefas (precisa dos projetos válidos para resolver projectName).
        $validTasks = [];
        foreach ($tasks as $index => $task) {
            $taskErrors = $this->validateTask($task, $index, $user, $validProjects);
            $errors = array_merge($errors, $taskErrors);
            if ($taskErrors === []) {
                $validTasks[] = $task;
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'projectsCreated' => 0,
                'tasksCreated' => 0,
                'errors' => $errors,
            ];
        }

        // Importar numa transação: nada é gravado se algo falhar.
        return DB::transaction(function () use ($user, $validProjects, $validTasks): array {
            $projectsCreated = 0;
            $projectIdMap = []; // índice no payload → UUID criado

            foreach ($validProjects as $index => $project) {
                $newProject = Project::create([
                    'user_id' => $user->id,
                    'name' => $project['name'],
                    'description' => $project['description'] ?? '',
                    'category' => $project['category'],
                    'urgency' => $project['urgency'],
                    'status' => ProjectStatus::Active->value,
                    'can_postpone' => $project['canPostpone'] ?? false,
                    'due_date' => $project['dueDate'] ?? null,
                    'next_step' => $project['nextStep'] ?? '',
                    'color' => $project['color'] ?? null,
                ]);

                app(RecordActivity::class)->record(
                    $user,
                    $newProject,
                    ActivityType::Created,
                    'Projeto importado',
                );

                $projectIdMap[$index] = $newProject->id;
                $projectsCreated++;
            }

            $tasksCreated = 0;

            foreach ($validTasks as $index => $task) {
                $projectId = $this->resolveTaskProjectId($task, $index, $user, $validProjects, $projectIdMap);

                $newTask = Task::create([
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'title' => $task['title'],
                    'description' => $task['description'] ?? '',
                    'category' => $task['category'],
                    'urgency' => $task['urgency'],
                    'status' => TaskStatus::Todo->value,
                    'can_postpone' => $task['canPostpone'] ?? false,
                    'due_date' => $task['dueDate'] ?? null,
                    'due_time' => $task['dueTime'] ?? null,
                    'next_step' => $task['nextStep'] ?? '',
                    'estimate_minutes' => $task['estimateMinutes'] ?? null,
                    'tags' => $task['tags'] ?? [],
                    'tracked_seconds' => 0,
                ]);

                app(RecordActivity::class)->record(
                    $user,
                    $newTask,
                    ActivityType::Created,
                    'Tarefa importada',
                );

                $tasksCreated++;
            }

            return [
                'ok' => true,
                'projectsCreated' => $projectsCreated,
                'tasksCreated' => $tasksCreated,
            ];
        });
    }

    /**
     * Normaliza o corpo para extrair projetos e tarefas. Aceita o formato
     * `{ projects?: [], tasks?: [] }` ou o `AppData` exportado.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function normalizeProjects(array $body): array
    {
        $projects = $body['projects'] ?? [];

        if (! is_array($projects)) {
            $projects = [];
        }

        return array_values($projects);
    }

    private function normalizeTasks(array $body): array
    {
        $tasks = $body['tasks'] ?? [];

        if (! is_array($tasks)) {
            $tasks = [];
        }

        return array_values($tasks);
    }

    /**
     * @return list<string>
     */
    private function validateProject(array $project, int $index, User $user): array
    {
        $prefix = 'projects['.$index.']';
        $errors = [];

        // name: obrigatório, ≥ 2
        if (! isset($project['name']) || ! is_string($project['name']) || trim($project['name']) === '') {
            $errors[] = $prefix.': nome é obrigatório.';
        } elseif (mb_strlen(trim($project['name'])) < 2) {
            $errors[] = $prefix.': nome tem de ter pelo menos 2 caracteres.';
        }

        // category: deve existir nas categorias do utilizador
        if (isset($project['category']) && ! is_string($project['category'])) {
            $errors[] = $prefix.': categoria não é válida.';
        } elseif (isset($project['category']) && is_string($project['category'])) {
            $exists = $user->categories()
                ->where('name_key', Category::nameKey($project['category']))
                ->exists();
            if (! $exists) {
                $errors[] = $prefix.': categoria não encontrada ("'.$project['category'].'").';
            }
        }

        // urgency: enum válido
        if (isset($project['urgency']) && ! in_array($project['urgency'], array_column(Urgency::cases(), 'value'), true)) {
            $errors[] = $prefix.': urgência inválida ("'.$project['urgency'].'").';
        }

        // dueDate: YYYY-MM-DD
        if (isset($project['dueDate']) && $project['dueDate'] !== null && $project['dueDate'] !== '') {
            if (! is_string($project['dueDate']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $project['dueDate'])) {
                $errors[] = $prefix.': prazo não é válido ("'.$project['dueDate'].'").';
            }
        }

        // canPostpone: boolean
        if (isset($project['canPostpone']) && ! is_bool($project['canPostpone'])) {
            $errors[] = $prefix.': canPostpone não é válido.';
        }

        // color: string if present
        if (isset($project['color']) && $project['color'] !== null && ! is_string($project['color'])) {
            $errors[] = $prefix.': cor não é válida.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateTask(array $task, int $index, User $user, array $validProjects): array
    {
        $prefix = 'tasks['.$index.']';
        $errors = [];

        // title: obrigatório, ≥ 2
        if (! isset($task['title']) || ! is_string($task['title']) || trim($task['title']) === '') {
            $errors[] = $prefix.': título é obrigatório.';
        } elseif (mb_strlen(trim($task['title'])) < 2) {
            $errors[] = $prefix.': título tem de ter pelo menos 2 caracteres.';
        }

        // category: deve existir nas categorias do utilizador
        if (isset($task['category']) && ! is_string($task['category'])) {
            $errors[] = $prefix.': categoria não é válida.';
        } elseif (isset($task['category']) && is_string($task['category'])) {
            $exists = $user->categories()
                ->where('name_key', Category::nameKey($task['category']))
                ->exists();
            if (! $exists) {
                $errors[] = $prefix.': categoria não encontrada ("'.$task['category'].'").';
            }
        }

        // urgency: enum válido
        if (isset($task['urgency']) && ! in_array($task['urgency'], array_column(Urgency::cases(), 'value'), true)) {
            $errors[] = $prefix.': urgência inválida ("'.$task['urgency'].'").';
        }

        // dueDate: aceita hora, mas não pode ser no passado
        if (isset($task['dueDate']) && $task['dueDate'] !== null && $task['dueDate'] !== '') {
            if (! is_string($task['dueDate'])) {
                $errors[] = $prefix.': prazo não é válido ("'.$task['dueDate'].'").';
            } else {
                try {
                    $due = CarbonImmutable::parse($task['dueDate']);
                } catch (\Throwable $e) {
                    $errors[] = $prefix.': prazo não é válido ("'.$task['dueDate'].'").';
                    $due = null;
                }

                if ($due !== null) {
                    $today = app(UserClock::class)->today($user)->format('Y-m-d');

                    if ($due->format('Y-m-d') < $today) {
                        $errors[] = $prefix.'.dueDate: prazo no passado.';
                    }
                }
            }
        }

        // estimateMinutes: integer if present
        if (isset($task['estimateMinutes']) && $task['estimateMinutes'] !== null && ! is_int($task['estimateMinutes'])) {
            $errors[] = $prefix.': estimateMinutes não é válido.';
        }

        // tags: array if present
        if (isset($task['tags']) && $task['tags'] !== null && ! is_array($task['tags'])) {
            $errors[] = $prefix.': tags não é válido.';
        }

        // canPostpone: boolean if present
        if (isset($task['canPostpone']) && ! is_bool($task['canPostpone'])) {
            $errors[] = $prefix.': canPostpone não é válido.';
        }

        // projectName / projectId: resolved later
        if (! isset($task['projectName']) && ! isset($task['projectId'])) {
            // Sem projeto é válido (tarefa solta).
        } elseif (isset($task['projectName']) && ! is_string($task['projectName'])) {
            $errors[] = $prefix.': projectName não é válido.';
        } elseif (isset($task['projectId']) && ! is_string($task['projectId'])) {
            $errors[] = $prefix.': projectId não é válido.';
        }

        return $errors;
    }

    /**
     * Resolve o ID do projeto para a tarefa.
     * `projectName`: resolve primeiro nos projetos do payload, depois nos do utilizador (case-insensitive).
     * `projectId`: usa diretamente se for um projeto do utilizador.
     */
    private function resolveTaskProjectId(
        array $task,
        int $index,
        User $user,
        array $validProjects,
        array $projectIdMap,
    ): ?string {
        $prefix = 'tasks['.$index.']';

        // projectId direto
        if (isset($task['projectId']) && is_string($task['projectId']) && $task['projectId'] !== '') {
            $owned = $user->projects()->whereKey($task['projectId'])->exists();

            if ($owned) {
                return $task['projectId'];
            }

            // Projeto de outro utilizador ou inexistente: erro
            return null;
        }

        // projectName: resolve nos projetos do payload primeiro, depois nos do utilizador
        if (isset($task['projectName']) && is_string($task['projectName']) && trim($task['projectName']) !== '') {
            $projectName = trim($task['projectName']);
            $nameKey = Category::nameKey($projectName);

            // Primeiro: projetos do payload (mesma importação)
            foreach ($validProjects as $pIndex => $project) {
                if (Category::nameKey($project['name'] ?? '') === $nameKey) {
                    return $projectIdMap[$pIndex] ?? null;
                }
            }

            // Depois: projetos existentes do utilizador (case-insensitive)
            $existing = $user->projects()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($projectName)])
                ->first();

            if ($existing instanceof Project) {
                return $existing->id;
            }

            return null;
        }

        return null;
    }
}
