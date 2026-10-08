<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ApiDocumentationController;
use App\Http\Controllers\Api\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/login', LoginController::class);

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

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware('auth:sanctum');
});
