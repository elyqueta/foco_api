<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TickService;
use Illuminate\Http\JsonResponse;

/**
 * Agendador externo (doc 10). Endpoint de sistema (sem versão, como o
 * `health` e a documentação): quem chama é um cron externo com o segredo
 * `CRON_SECRET` — em produção não há worker nem Cron Job pagos.
 *
 * `POST`/`GET /api/internal/scheduler/tick` corre o `TickService`. Com
 * outro tick a correr responde `202 { ok, skipped: "locked" }`; nos outros
 * casos `200` com o resumo do trabalho feito.
 */
class SchedulerController extends Controller
{
    public function __construct(private readonly TickService $ticks) {}

    public function tick(): JsonResponse
    {
        $result = $this->ticks->run();

        $locked = ($result['skipped'] ?? null) === TickService::SKIPPED_LOCKED;

        return response()->json($result, $locked ? 202 : 200);
    }
}
