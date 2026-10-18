<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Exports;

use App\Actions\Reports\ProductivityReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Exports\ProductivityReportRequest;
use App\Http\Resources\V1\ProductivityReportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Relatório de Produtividade (`GET /api/v1/exports/productivity-report`).
 *
 * Devolve todos os dados já calculados para o front-end gerar o PDF. O
 * back-end não gera PDF.
 *
 * Controller fino: a lógica de cálculo está na action
 * `App\Actions\Reports\ProductivityReport`.
 */
class ProductivityReportController extends Controller
{
    public function __invoke(
        ProductivityReportRequest $request,
        ProductivityReport $report,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $period = $request->computePeriod($user);
        $data = $report->forUser($user, $period);

        return response()->json(new ProductivityReportResource($data));
    }
}
