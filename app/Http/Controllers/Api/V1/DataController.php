<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\ProvisionUserDefaults;
use App\Http\Controllers\Controller;
use App\Models\ActivityEntry;
use App\Models\TaskTimeEntry;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dados do utilizador (doc 11 §"Dados"): reset, seed-demo e reset-demo.
 *
 * - `DELETE /api/v1/data` — apaga todos os dados do utilizador; recria categorias padrão.
 * - `POST /api/v1/data/seed-demo` — semeia dados de exemplo se o utilizador não tiver projetos nem tarefas.
 * - `POST /api/v1/data/reset-demo` — apaga tudo e semeia demo.
 */
class DataController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $confirm = $request->input('confirm');

        if ($confirm !== 'APAGAR') {
            return response()->json([
                'message' => 'Confirmação não é "APAGAR".',
                'code' => 'NO_CHANGES',
            ], 422);
        }

        DB::transaction(function () use ($user): void {
            TaskTimeEntry::whereHas('task', fn ($q) => $q->where('user_id', $user->id))->delete();
            $user->notifications()->delete();
            ActivityEntry::where('user_id', $user->id)->delete();
            $user->tasks()->delete();
            $user->projects()->delete();
            $user->categories()->where('is_default', false)->delete();

            app(ProvisionUserDefaults::class)->handle($user, protected: false);
        });

        return response()->json([
            'message' => 'Todos os dados foram apagados e as categorias padrão foram repostas.',
        ], 200);
    }

    public function seedDemo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->projects()->exists() || $user->tasks()->exists()) {
            return response()->json([
                'message' => 'O utilizador já tem projetos ou tarefas.',
                'code' => 'DATA_NOT_EMPTY',
            ], 409);
        }

        app(DemoDataSeeder::class)->run($user);

        return response()->json([
            'message' => 'Dados de exemplo semeados com sucesso.',
        ], 201);
    }

    public function resetDemo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $confirm = $request->input('confirm');

        if ($confirm !== 'APAGAR') {
            return response()->json([
                'message' => 'Confirmação não é "APAGAR".',
                'code' => 'NO_CHANGES',
            ], 422);
        }

        DB::transaction(function () use ($user): void {
            TaskTimeEntry::whereHas('task', fn ($q) => $q->where('user_id', $user->id))->delete();
            $user->notifications()->delete();
            ActivityEntry::where('user_id', $user->id)->delete();
            $user->tasks()->delete();
            $user->projects()->delete();
            $user->categories()->where('is_default', false)->delete();

            app(ProvisionUserDefaults::class)->handle($user, protected: false);
        });

        app(DemoDataSeeder::class)->run($user);

        return response()->json([
            'message' => 'Todos os dados foram apagados e os dados de exemplo foram semeados.',
        ], 200);
    }
}
