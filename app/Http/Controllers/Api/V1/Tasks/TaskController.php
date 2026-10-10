<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tasks;

use App\Actions\Categories\ResolveCategory;
use App\Actions\Tasks\AddTaskNote;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ExpireOverdueTasks;
use App\Actions\Tasks\PostponeTask;
use App\Actions\Tasks\ReopenTask;
use App\Actions\Tasks\TaskQuery;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Urgency;
use App\Http\Controllers\Api\V1\Concerns\FindsOwnedTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tasks\ListTasksRequest;
use App\Http\Requests\V1\Tasks\PostponeTaskRequest;
use App\Http\Requests\V1\Tasks\StoreTaskNoteRequest;
use App\Http\Requests\V1\Tasks\StoreTaskRequest;
use App\Http\Requests\V1\Tasks\UpdateTaskRequest;
use App\Http\Resources\V1\ActivityEntryResource;
use App\Http\Resources\V1\TaskResource;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use App\Support\PageResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use FindsOwnedTask;

    /**
     * Tecto do histórico devolvido no detalhe: entradas mais recentes
     * primeiro, com um máximo razoável.
     */
    private const ACTIVITY_LIMIT = 100;

    /**
     * Lista as tarefas do utilizador (sem `activity`), paginada. Corre a
     * expiração preguiçosa antes de listar (doc 07, regras §5.2) para que uma
     * tarefa vencida apareça já `expired`. Aplica os filtros opcionais.
     *
     * Por omissão traz o **histórico completo** — concluídas e expiradas
     * incluídas (pedido do utilizador: são fundamentais para o histórico);
     * `openOnly=true` restringe às abertas e `status=…` filtra à mão.
     *
     * Resposta: `{ items, page, perPage, total, totalPages, counts }`, onde
     * `counts` traz as contagens por estado com os mesmos filtros **excepto
     * o de estado** — para o front desenhar os separadores sem pedir uma
     * lista por estado.
     */
    public function index(ListTasksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        app(ExpireOverdueTasks::class)->forUser($user);

        $filters = $this->normalizeFilters($request->validated(), $user);

        if ($filters === null) {
            // Categoria do filtro inexistente → lista vazia (é um filtro).
            return response()->json(PageResponse::empty());
        }

        $filters['page'] = max(1, (int) $request->integer('page', 1));
        $filters['perPage'] = max(1, (int) $request->integer('perPage', PageResponse::DEFAULT_PER_PAGE));

        // `with('activeTimeEntry')`: a relação aberta de cada tarefa vem numa
        // única query extra — o bloco `timer` do recurso não faz N+1.
        $tasks = app(TaskQuery::class)->paginate(
            $user->tasks()->with('activeTimeEntry')->getQuery(),
            $filters,
            $user,
            $filters['page'],
            $filters['perPage'],
        );

        // Sem N+1 no `isOverdue` (que consulta o fuso do utilizador): a
        // relação `user` é a mesma para toda a página, por isso é injectada em
        // vez de carregada tarefa a tarefa.
        $tasks->getCollection()->each(fn (Task $task) => $task->setRelation('user', $user));

        // `items` no formato do contrato (TaskResource), não o modelo cru.
        $tasks->through(fn (Task $task): array => TaskResource::make($task)->resolve());

        $counts = app(TaskQuery::class)->statusCounts($user->tasks()->getQuery(), $filters, $user);

        return response()->json(PageResponse::from($tasks, $counts));
    }

    /**
     * Cria a tarefa e devolve o detalhe (com a atividade `created`).
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('create', Task::class);

        $task = app(CreateTask::class)->handle($user, $request->validated());

        return $this->present($task, $user, 201);
    }

    /**
     * Detalhe da tarefa, com `activity` (mais recente primeiro).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $task = $this->findTask($request, (string) $id);

        $task->load([
            'activeTimeEntry',
            'activity' => fn ($query) => $query->limit(self::ACTIVITY_LIMIT),
        ]);

        return $this->present($task, $request->user());
    }

    /**
     * Atualização parcial (inclui transições de estado pela máquina de
     * estados). Sem nenhum valor diferente responde `422 NO_CHANGES`.
     */
    public function update(UpdateTaskRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('update', $task);

        $task = app(UpdateTask::class)->handle($user, $task, $request->validated());

        return $this->present($task, $user);
    }

    /**
     * Apaga a tarefa, o tempo e o histórico. Responde `200` com a mensagem
     * de sucesso, como o `DELETE /projects/{id}` e `DELETE /categories/{name}`
     * (um `204` não pode ter corpo) — decisão documentada em PROGRESS.md.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('delete', $task);

        app(DeleteTask::class)->handle($user, $task);

        return response()->json(['message' => 'Tarefa removida.'], 200);
    }

    /**
     * Conclui a tarefa (`→ done`). Devolve `{ task, suggestCompleteProject? }`.
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('update', $task);

        $result = app(CompleteTask::class)->handle($user, $task);

        $payload = ['task' => $this->resource($result['task'], $user)];

        if ($result['suggestCompleteProject'] !== null) {
            $payload['suggestCompleteProject'] = $result['suggestCompleteProject'];
        }

        return response()->json($payload);
    }

    /**
     * Reabre a tarefa (`done`/`expired → todo`). Devolve `{ task }`.
     */
    public function reopen(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('update', $task);

        $task = app(ReopenTask::class)->handle($user, $task);

        return response()->json(['task' => $this->resource($task, $user)]);
    }

    /**
     * Adia a tarefa (`{ dueDate }`, `→ postponed`). Devolve `{ task }`.
     */
    public function postpone(PostponeTaskRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('update', $task);

        $task = app(PostponeTask::class)->handle($user, $task, $request->validated());

        return response()->json(['task' => $this->resource($task, $user)]);
    }

    /**
     * Nota da tarefa → atividade `note`; devolve a entrada criada (201).
     */
    public function storeNote(StoreTaskNoteRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $task = $this->findTask($request, (string) $id);

        $this->authorize('update', $task);

        $entry = app(AddTaskNote::class)->handle(
            $user,
            $task,
            (string) $request->validated('text'),
        );

        return ActivityEntryResource::make($entry)->response()->setStatusCode(201);
    }

    /**
     * Normaliza os filtros validados para o `TaskQuery`. Devolve `null`
     * quando a categoria do filtro não existe (lista vazia).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function normalizeFilters(array $validated, User $user): ?array
    {
        $filters = [];

        if (isset($validated['category']) && is_string($validated['category']) && trim($validated['category']) !== '') {
            $category = app(ResolveCategory::class)->handle($user, (string) $validated['category']);

            if (! $category instanceof Category) {
                return null;
            }

            $filters['category'] = $category->name;
        }

        if (isset($validated['urgency']) && is_string($validated['urgency']) && $validated['urgency'] !== '') {
            $filters['urgency'] = Urgency::tryFrom((string) $validated['urgency']);
        }

        if (isset($validated['status']) && is_string($validated['status']) && trim($validated['status']) !== '') {
            $filters['status'] = array_values(array_filter(
                array_map('trim', explode(',', (string) $validated['status'])),
                fn (string $s): bool => $s !== '',
            ));
        }

        if (isset($validated['projectId']) && is_string($validated['projectId'])) {
            $filters['projectId'] = $validated['projectId'];
        }

        foreach (['q', 'dueFrom', 'dueTo', 'sort'] as $key) {
            if (isset($validated[$key]) && is_string($validated[$key])) {
                $filters[$key] = $validated[$key];
            }
        }

        // Por omissão a lista traz o histórico completo (concluídas e
        // expiradas incluídas). `openOnly=true` restringe às abertas.
        // `includeDone`/`includeExpired` continuam aceites (o front pode
        // ainda enviá-los) mas já não alteram o resultado.
        $filters['openOnly'] = $this->boolish($validated, 'openOnly');

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function boolish(array $validated, string $key): bool
    {
        return isset($validated[$key]) && filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function present(Task $task, mixed $user, int $status = 200): JsonResponse
    {
        return $this->resource($task, $user)->response()->setStatusCode($status);
    }

    private function resource(Task $task, mixed $user): TaskResource
    {
        if ($user instanceof User) {
            $task->setRelation('user', $user);
        }

        return TaskResource::make($task);
    }
}
