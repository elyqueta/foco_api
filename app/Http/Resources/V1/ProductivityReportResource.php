<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Relatório de Produtividade (`GET /api/v1/exports/productivity-report`).
 *
 * Envolve o array já calculado pela action `App\Actions\Reports\ProductivityReport`
 * na chave `data`, conforme o contrato da API.
 *
 * @property-read array $resource
 */
class ProductivityReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->resource,
        ];
    }
}
