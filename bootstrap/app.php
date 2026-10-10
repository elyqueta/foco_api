<?php

use App\Exceptions\DomainRuleException;
use App\Http\Middleware\EnsureValidJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // App API-only: não existe rota `login` para redireccionar. Sem isto,
        // um pedido sem Accept: application/json e sem token faz
        // `route('login')` lançar RouteNotFoundException → 500 com stack
        // trace em vez de 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        // Padrão da API: JSON inválido no corpo → 400 INVALID_JSON. Fica na
        // stack global (e não no grupo `api`) porque o `throttle` tem
        // prioridade superior e correria primeiro — assim o corpo inválido é
        // detectado antes de qualquer middleware de rota.
        $middleware->prepend(EnsureValidJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Helper: erro JSON da API. Formato único `{ message, code }` em PT —
        // sem duplicar informação nem expor mensagens técnicas do framework
        // (a excepção completa fica sempre em storage/logs/laravel.log).
        $apiError = function (string $message, string $code, int $status, ?Throwable $e = null, array $headers = []) {
            return response()->json(['message' => $message, 'code' => $code], $status, $headers);
        };

        // Regra de negócio (doc: { message, code }).
        $exceptions->render(function (DomainRuleException $e, Request $request) use ($apiError) {
            return $apiError($e->getMessage(), $e->errorCode, $e->status, $e);
        });

        // Recurso inexistente (contrato: "Não encontrado.") vs rota
        // inexistente (ROUTE_NOT_FOUND). O Handler converte
        // ModelNotFoundException em NotFoundHttpException antes dos renders,
        // por isso a distinção vem do `previous`.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($apiError) {
            if ($request->expectsJson() || $request->is('api/*')) {
                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    return response()->json(['message' => 'Não encontrado.'], 404);
                }

                return $apiError(
                    'A rota /'.$request->path().' não foi encontrada.',
                    'ROUTE_NOT_FOUND',
                    404,
                    $e,
                );
            }
        });

        // Método errado para a rota: diz o método e os permitidos.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($apiError) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $allowed = $e->getHeaders()['Allow'] ?? null;
                $message = 'O método '.$request->method().' não corresponde à rota /'.$request->path().'.';

                if (is_string($allowed) && $allowed !== '') {
                    $message .= ' Métodos permitidos: '.$allowed.'.';
                }

                return $apiError($message, 'METHOD_NOT_ALLOWED', 405, $e, $e->getHeaders());
            }
        });

        // Falha de comunicação com a base de dados ou serviço externo.
        // `QueryException` estende `PDOException` e uma ligação falhada lança
        // `PDOException` directamente — apanhar a classe base cobre os dois.
        // `report()` é deduplicado: não duplica se o Handler já registou.
        $exceptions->render(function (PDOException $e, Request $request) use ($apiError) {
            if ($request->expectsJson() || $request->is('api/*')) {
                report($e);

                return $apiError(
                    'Não foi possível comunicar com a base de dados. Tenta novamente.',
                    'DATABASE_ERROR',
                    503,
                    $e,
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return response()->json([
                'message' => 'Demasiadas tentativas. Tenta novamente dentro de instantes.',
            ], 429, $e->getHeaders());
        });

        // Qualquer outro erro: mensagem accionável em vez de 500 confuso.
        // Exceções que o Laravel sabe renderizar nativamente (validação 422,
        // resposta lançada, abort) passam pelo tratamento padrão. Tem de ser
        // o último render (apanha tudo).
        $exceptions->render(function (Throwable $e, Request $request) use ($apiError) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            foreach ([
                HttpResponseException::class,
                ValidationException::class,
                AuthorizationException::class,
                HttpException::class,
            ] as $native) {
                if ($e instanceof $native) {
                    return null;
                }
            }

            report($e);

            return $apiError(
                'Ocorreu um erro inesperado. Tenta novamente; se persistir, contacta o suporte.',
                'INTERNAL_ERROR',
                500,
                $e,
            );
        });
    })->create();
