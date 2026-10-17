<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\Api\SchedulerController;
use App\Http\Middleware\VerifyCronSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoints de sistema (sem versão)
|--------------------------------------------------------------------------
| Health e documentação não pertencem a uma versão do domínio: ficam na base
| /api, como exige o contrato ("sem prefixo de versão na v1"). O status da
| raiz (`GET /`) e a UI da documentação (`GET /docs`) são servidos por
| routes/web.php — não duplicar aqui.
*/
Route::get('/health', function () {
    if (app()->environment('testing')) {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'db' => true,
            'time' => now()->toIso8601String(),
        ]);
    }

    $db = false;
    try {
        DB::connection()->getPdo();
        $db = true;
    } catch (Throwable $e) {
        report($e);
        $db = false;
    }

    return response()->json([
        'status' => $db ? 'ok' : 'degraded',
        'app' => config('app.name'),
        'db' => $db,
        'time' => now()->toIso8601String(),
    ], $db ? 200 : 503);
});

Route::get('/docs.json', [ApiDocumentationController::class, 'openApi']);

/*
|--------------------------------------------------------------------------
| Agendador externo (doc 10)
|--------------------------------------------------------------------------
| O tick é infraestrutura, não rota de domínio: quem chama é um cron
| externo (cron-job.org, GitHub Actions) com o segredo `X-Cron-Secret`, não
| um utilizador com token Sanctum. Aceita GET e POST — alguns agendadores
| só fazem um dos dois — e ambos correm exactamente o mesmo tick, protegido
| pelo lock `foco:tick`. O throttle (6/min) limita martelar no segredo.
*/
Route::match(['GET', 'POST'], '/internal/scheduler/tick', [SchedulerController::class, 'tick'])
    ->middleware([VerifyCronSecret::class, 'throttle:6,1'])
    ->name('internal.scheduler.tick');

/*
|--------------------------------------------------------------------------
| API versionada
|--------------------------------------------------------------------------
| A versão vive na estrutura do código (namespaces App\Http\*\V1 e
| routes/api/v1.php), não no URL: a v1 não tem prefixo, como exige o
| contrato. Uma v2 passa por routes/api/v2.php + namespaces App\Http\*\V2,
| sem tocar na v1.
*/
require __DIR__.'/api/v1.php';
