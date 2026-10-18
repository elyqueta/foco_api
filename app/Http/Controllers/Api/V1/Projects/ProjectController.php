<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Categories\ResolveCategory;
use App\Actions\Projects\AddProjectNote;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Projects\ListProjectsRequest;
use App\Http\Requests\V1\Projects\StoreProjectNoteRequest;
use App\Http\Requests\V1\Projects\StoreProjectRequest;
use App\Http\Requests\V1\Projects\UpdateProjectRequest;
use App\Http\Resources\V1\ActivityEntryResource;
use App\Http\Resources\V1\ProjectResource;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Support\PageResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Tecto do histórico devolvido no detalhe: as entradas mais recentes
     * primeiro, com um máximo razoável para a resposta não crescer sem fim.
     */
    private const ACTIVITY_LIMIT = 100;

    /**
     * Lista os projetos do utilizador (sem `activity`), com `progress`.
     * Filtros: `category`, `status` e `q` (nome/descrição). A categoria é
     * resolvida para o nome canónico; uma categoria inexistente devolve
     * lista vazia em vez de erro. Paginada (`page`/`perPage`) com totais e
     * contagens por estado com os mesmos filtros **excepto o de estado**.
     *
     * Resposta: `{ items, page, perPage, total, totalPages, counts }`.
     */
    public function index(ListProjectsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validated();

        $category = $this->resolveCategoryFilter($user, $filters['category'] ?? null);

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, (int) $request->integer('perPage', PageResponse::DEFAULT_PER_PAGE));

        if ($category === false) {
            return response()->json(PageResponse::empty($page, $perPage));
        }

        $status = isset($filters['status']) && $filters['status'] !== '' ? (string) $filters['status'] : null;
        $term = isset($filters['q']) && trim((string) $filters['q']) !== '' ? (string) $filters['q'] : null;

        $projects = $user->projects()
            ->withCount(Project::progressCounts())
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($term !== null, fn ($query) => $query->search($term))
            ->orderByDesc('created_at')
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);

        // `items` no formato do contrato (ProjectResource), não o modelo cru.
        $projects->through(fn (Project $project): array => ProjectResource::make($project)->resolve());

        return response()->json(PageResponse::from(
            $projects,
            $this->statusCounts($user, $category, $term),
        ));
    }

    /**
     * Contagens por estado com os mesmos filtros **excepto o de estado** —
     * para o front desenhar os separadores sem pedir uma lista por estado.
     *
     * @return array<string, int>
     */
    private function statusCounts(User $user, Category|string|false|null $category, ?string $term): array
    {
        $rows = $user->projects()
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($term !== null, fn ($query) => $query->search($term))
            ->getQuery()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (ProjectStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * Cria um projeto e devolve o detalhe (com a atividade `created`).
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('create', Project::class);

        $project = app(CreateProject::class)->handle($user, $request->validated());

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    /**
     * Detalhe do projeto, com `activity` (mais recente primeiro) e
     * `progress`.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $project = $this->findProject($request, (string) $id);

        return ProjectResource::make($this->loadActivity($project))->response();
    }

    /**
     * Atualização parcial. Sem nenhum valor diferente do atual responde
     * `422 NO_CHANGES` (padrão da API).
     */
    public function update(UpdateProjectRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->findProject($request, (string) $id);

        $this->authorize('update', $project);

        $project = app(UpdateProject::class)->handle($user, $project, $request->validated());

        return ProjectResource::make($project)->response();
    }

    /**
     * Apaga o projeto e todas as suas tarefas (cascade) e o histórico —
     * a confirmação é do front. Responde `200` com a mensagem de sucesso,
     * como o `DELETE /categories/{name}` (um `204` não pode ter corpo).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->findProject($request, (string) $id);

        $this->authorize('delete', $project);

        app(DeleteProject::class)->handle($user, $project);

        return response()->json(['message' => 'Projeto removido.'], 200);
    }

    /**
     * Nota do projeto → atividade `note`; devolve a entrada criada.
     */
    public function storeNote(StoreProjectNoteRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->findProject($request, (string) $id);

        $this->authorize('update', $project);

        $entry = app(AddProjectNote::class)->handle(
            $user,
            $project,
            (string) $request->validated('text'),
        );

        return ActivityEntryResource::make($entry)->response()->setStatusCode(201);
    }

    /**
     * Procuras sempre dentro dos projetos do utilizador (regra 6): projeto
     * de outro utilizador devolve `404 Não encontrado.`, nunca 403. Um `id`
     * que não é UUID devolve também 404 — no PostgreSQL a coluna é `uuid` e
     * a query falharia com erro de sintaxe (503) em vez de "não encontrado".
     */
    private function findProject(Request $request, string $id): Project
    {
        if (! Str::isUuid($id)) {
            // Recurso inexistente (contrato) — diferente de rota inexistente.
            throw new ModelNotFoundException;
        }

        /** @var User $user */
        $user = $request->user();

        $project = $user->projects()
            ->withCount(Project::progressCounts())
            ->whereKey($id)
            ->first();

        if (! $project instanceof Project) {
            // Recurso inexistente (contrato) — diferente de rota inexistente.
            throw new ModelNotFoundException;
        }

        $this->authorize('view', $project);

        return $project;
    }

    /**
     * Carrega o histórico do projeto com tecto (mais recente primeiro).
     */
    private function loadActivity(Project $project): Project
    {
        return $project->load([
            'activity' => fn ($query) => $query->limit(self::ACTIVITY_LIMIT),
        ]);
    }

    /**
     * Nome canónico da categoria do filtro. Devolve `null` quando o filtro
     * não foi enviado e `false` quando a categoria não existe (lista vazia).
     */
    private function resolveCategoryFilter(User $user, mixed $value): Category|string|false|null
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $category = app(ResolveCategory::class)->handle($user, $value);

        if (! $category instanceof Category) {
            return false;
        }

        return $category->name;
    }
}
