<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Categories\CategoryController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — auth, definições, categorias e projetos
|--------------------------------------------------------------------------
| Prefixo /api/v1 no URL E a versão na estrutura do código: controllers em
| App\Http\Controllers\Api\V1, requests em App\Http\Requests\V1, resources
| em App\Http\Resources\V1. Uma v2 será routes/api/v2.php + namespaces
| App\Http\*\V2 + prefixo /api/v2, sem tocar na v1.
*/

Route::prefix('v1')->group(function (): void {
    // Auth e definições do utilizador.
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/password', [AuthController::class, 'updatePassword']);
        Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::patch('/settings', [SettingsController::class, 'update']);

        // Categorias (Fase 5).
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::delete('/categories/{name}', [CategoryController::class, 'destroy']);

        // Projetos (Fase 6).
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{id}', [ProjectController::class, 'show']);
        Route::patch('/projects/{id}', [ProjectController::class, 'update']);
        Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
        Route::post('/projects/{id}/notes', [ProjectController::class, 'storeNote']);
    });
});
