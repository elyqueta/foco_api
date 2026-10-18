<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Enums\Urgency;
use App\Models\User;
use App\Support\PageResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Filtros da listagem de tarefas (doc 07). Objeto de query: recebe o builder
 * já com scoping por utilizador e aplica os filtros + ordenação. Não faz
 * queries — quem executa é o controller, o que mantém a listagem sem N+1.
 *
 * Todos os filtros são opcionais: `category`, `urgency`, `status` (um ou
 * vários separados por vírgula), `projectId` (UUID ou `none`), `q` (título,
 * descrição, nome do projeto e tags), `dueFrom`/`dueTo` (calendário),
 * `openOnly` (restringe às tarefas abertas) e `sort`
 * (`urgency|dueDate|createdAt`). Sem `status` nem `openOnly` a lista traz o
 * **histórico completo** (inclui `done` e `expired`). A página é `page`
 * (≥ 1) e `perPage` (1…`PageResponse::MAX_PER_PAGE`,
 * `PageResponse::DEFAULT_PER_PAGE` por omissão).
 */
class TaskQuery
{
    /**
     * @param  array<string, mixed>  $filters  filtros já normalizados
     */
    public function apply(Builder $query, array $filters, User $user): Builder
    {
        $query = $this->applyFilters($query, $filters, $user);

        return $this->applySort($query, is_string($filters['sort'] ?? null) ? $filters['sort'] : null);
    }

    /**
     * Lista paginada: filtros + ordenação + página pedida. O `perPage` é
     * limitado a `PageResponse::MAX_PER_PAGE` (a validação do request já o
     * garante; aqui é defesa para chamadas internas).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(Builder $query, array $filters, User $user, int $page, int $perPage): LengthAwarePaginator
    {
        $this->apply($query, $filters, $user);

        return $query->paginate(
            max(1, min($perPage, PageResponse::MAX_PER_PAGE)),
            ['*'],
            'page',
            max(1, $page),
        );
    }

    /**
     * Contagens por estado com os **mesmos filtros excepto o de estado**
     * (`status`/`openOnly`): o front desenha os separadores sem pedir uma
     * lista por estado. Sempre com todos os estados presentes (0 quando não
     * há tarefas).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(Builder $query, array $filters, User $user): array
    {
        $filters = Arr::except($filters, ['status', 'openOnly']);

        // Sem qualquer restrição de estado: contar por cima da lista padrão
        // (só abertos) daria sempre 0 em `done`/`expired`.
        $this->applyFilters($query, $filters, $user, false);

        $rows = $query->getQuery()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (TaskStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * Filtros sem ordenação nem página (partilhado pela listagem e pelas
     * contagens por estado).
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $user, bool $statusFilter = true): Builder
    {
        if (isset($filters['category']) && is_string($filters['category']) && $filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['urgency']) && $filters['urgency'] instanceof Urgency) {
            $query->where('urgency', $filters['urgency']->value);
        }

        if ($statusFilter) {
            $query->when($this->statusFilter($filters), function (Builder $q, array $statuses): void {
                $q->whereIn('status', $statuses);
            });
        }

        $query = $this->applyProjectFilter($query, $filters['projectId'] ?? null, $user);

        $query->when($this->term($filters), function (Builder $q, string $term): void {
            $this->applySearch($q, $term);
        });

        $query->when($this->dateFilter($filters['dueFrom'] ?? null), function (Builder $q, string $from): void {
            $q->whereDate('due_date', '>=', $from);
        });

        $query->when($this->dateFilter($filters['dueTo'] ?? null), function (Builder $q, string $to): void {
            $q->whereDate('due_date', '<=', $to);
        });

        return $query;
    }

    /**
     * Lista de status a filtrar.
     *
     * Sem filtro de estado a lista traz o **histórico completo** (todos os
     * estados, incluindo `done` e `expired`) — pedido do utilizador, agora
     * seguro porque a listagem é paginada. `openOnly=true` restringe aos
     * estados abertos; um `status` explícito continua a prevalecer sobre
     * tudo. `includeDone`/`includeExpired` são aceites por compatibilidade
     * mas já não mudam nada (o histórico já vinha por omissão).
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>|null
     */
    private function statusFilter(array $filters): ?array
    {
        if (! empty($filters['status']) && is_array($filters['status'])) {
            return array_values($filters['status']);
        }

        if (($filters['openOnly'] ?? false) === true) {
            return [
                TaskStatus::Todo->value,
                TaskStatus::InProgress->value,
                TaskStatus::Postponed->value,
            ];
        }

        // `null` = sem restrição de estado (histórico completo).
        return null;
    }

    /**
     * `projectId={uuid}` filtra pelo projeto do utilizador; `projectId=none`
     * devolve as tarefas soltas (sem projeto). Um UUID de outro utilizador é
     * tratado como projeto inexistente → lista vazia (nunca 404: é filtro).
     */
    private function applyProjectFilter(Builder $query, mixed $projectId, User $user): Builder
    {
        if (! is_string($projectId) || $projectId === '') {
            return $query;
        }

        if ($projectId === 'none') {
            return $query->whereNull('project_id');
        }

        $owned = $user->projects()->whereKey($projectId)->exists();

        if (! $owned) {
            // Projeto de outro utilizador (ou inexistente): nenhuma tarefa do
            // utilizador lhe pertence — liga a zero resultados de forma segura.
            $query->whereRaw('1 = 0');
        } else {
            $query->where('project_id', $projectId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function term(array $filters): ?string
    {
        $term = $filters['q'] ?? null;

        if (! is_string($term) || trim($term) === '') {
            return null;
        }

        return trim($term);
    }

    /**
     * Pesquisa case-insensitive em título, descrição, nome do projeto e tags.
     * `LOWER(...) LIKE` funciona em PostgreSQL e SQLite (ao contrário do
     * `ilike`, exclusivo do PostgreSQL); as tags são JSON, comparadas pelo
     * texto serializado (`::text` no PostgreSQL, texto simples no SQLite).
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower($term).'%';
        $tagsColumn = $query->getConnection()->getDriverName() === 'pgsql' ? 'tags::text' : 'tags';

        $query->where(function (Builder $inner) use ($like, $tagsColumn): void {
            $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                ->orWhereHas('project', fn (Builder $p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhere(function (Builder $tags) use ($like, $tagsColumn): void {
                    $tags->whereRaw("LOWER({$tagsColumn}) LIKE ?", [$like]);
                });
        });
    }

    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Ordenação. `urgency` (omissão): urgência e depois prazo ascendente com
     * nulos por último. `dueDate`: prazo ascendente, nulos por último.
     * `createdAt`: mais recente primeiro.
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        $nullsLast = 'CASE WHEN due_date IS NULL THEN 1 ELSE 0 END';

        $urgencyOrder = 'CASE urgency '
            ."WHEN 'critical' THEN 0 "
            ."WHEN 'high' THEN 1 "
            ."WHEN 'medium' THEN 2 "
            ."WHEN 'low' THEN 3 "
            .'ELSE 4 END';

        match ($sort) {
            'dueDate' => $query->orderByRaw($nullsLast)->orderBy('due_date')->orderByDesc('created_at'),
            'createdAt' => $query->orderByDesc('created_at'),
            default => $query->orderByRaw($urgencyOrder)->orderByRaw($nullsLast)->orderBy('due_date')->orderByDesc('created_at'),
        };

        return $query;
    }
}
