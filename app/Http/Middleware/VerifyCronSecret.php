<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agendador externo (doc 10): só quem tem o segredo chama o tick —
 * `POST /api/internal/scheduler/tick` e `GET /api/internal/scheduler/tick`.
 *
 * O cabeçalho `X-Cron-Secret` é comparado com o `CRON_SECRET` do servidor
 * usando `hash_equals` (comparativo de tempo constante). Sem segredo no
 * servidor o endpoint responde `503` — **nunca** aceita um tick sem segredo
 * configurado, mesmo que quem chama acerte a rota.
 */
class VerifyCronSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.cron.secret');

        if ($secret === '') {
            return response()->json([
                'message' => 'O agendador não está configurado neste servidor.',
                'code' => 'CRON_NOT_CONFIGURED',
            ], 503);
        }

        $provided = (string) $request->header('X-Cron-Secret', '');

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json([
                'message' => 'Segredo do agendador inválido ou em falta.',
                'code' => 'INVALID_CRON_SECRET',
            ], 401);
        }

        return $next($request);
    }
}
