<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiDocumentationController;
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
| API versionada
|--------------------------------------------------------------------------
| A versão vive na estrutura do código (namespaces App\Http\*\V1 e
| routes/api/v1.php), não no URL: a v1 não tem prefixo, como exige o
| contrato. Uma v2 passa por routes/api/v2.php + namespaces App\Http\*\V2,
| sem tocar na v1.
*/
require __DIR__.'/api/v1.php';
