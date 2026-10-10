<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Envelope das listagens paginadas (tarefas e projetos).
 *
 * A v1 devolvia arrays simples com tecto de 500 linhas porque "não paginava";
 * a pedido do utilizador as listas passaram a paginar e a trazer os totais.
 * `counts` leva as contagens por estado com os **mesmos filtros excepto o de
 * estado**, para o front desenhar separadores/tabs sem pedir uma lista por
 * estado.
 */
final class PageResponse
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public static function from(LengthAwarePaginator $page, array $counts = []): array
    {
        return [
            'items' => $page->items(),
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'totalPages' => $page->lastPage(),
            'counts' => $counts,
        ];
    }

    /**
     * Envelope vazio (filtro que não corresponde a nada — ex. categoria
     * inexistente), sempre com a mesma forma.
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public static function empty(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, array $counts = []): array
    {
        return [
            'items' => [],
            'page' => $page,
            'perPage' => $perPage,
            'total' => 0,
            'totalPages' => 0,
            'counts' => $counts,
        ];
    }
}
