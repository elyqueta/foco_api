<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidJson
{
    /**
     * Padrão da API: corpo com Content-Type application/json e conteúdo
     * não vazio tem de ser JSON válido — senão 400 INVALID_JSON (sem isto o
     * JSON inválido seria silenciosamente tratado como vazio e daria 422 de
     * validação, confuso para quem está a integrar).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $content = (string) $request->getContent();

        if ($request->isJson() && trim($content) !== '') {
            json_decode($content);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'message' => 'O corpo do pedido não é um JSON válido.',
                    'code' => 'INVALID_JSON',
                ], 400);
            }
        }

        return $next($request);
    }
}
