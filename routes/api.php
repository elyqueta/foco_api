<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Auth e definições do utilizador (contrato: base /api, sem prefixo de versão).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/password', [AuthController::class, 'updatePassword']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update']);
});

Route::prefix('v1')->group(function () {
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

    Route::get('/', function () {
        return response()->json([
            'name' => config('app.name'),
            'status' => 'ok',
        ]);
    });

    Route::get('/docs', [ApiDocumentationController::class, 'ui']);
    Route::get('/docs.json', [ApiDocumentationController::class, 'openApi']);
});
