<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Categories\CategoryController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — auth, definições e categorias
|--------------------------------------------------------------------------
| Base /api, sem prefixo de versão no URL (contrato). A versão está na
| estrutura: controllers em App\Http\Controllers\Api\V1, requests em
| App\Http\Requests\V1, resources em App\Http\Resources\V1.
*/

// Auth e definições do utilizador (contrato: base /api, sem prefixo de versão).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/password', [AuthController::class, 'updatePassword']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings', [SettingsController::class, 'update']);

    // Categorias (Fase 5).
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::delete('/categories/{name}', [CategoryController::class, 'destroy']);
});
