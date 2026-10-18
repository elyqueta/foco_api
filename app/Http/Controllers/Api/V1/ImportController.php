<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tasks\ImportData;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Tasks\ImportRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Importação de dados (doc 11 §"Importação"): aditiva, nunca apaga, validação
 * manual por campo, transação + rollback se houver qualquer erro.
 *
 * Corpo: `{ projects?: [], tasks?: [] }` ou o `AppData` exportado.
 * Resposta de sucesso: `201 { ok:true, projectsCreated: int, tasksCreated: int }`.
 * Resposta de erro: `422 { ok:false, errors: [...] }`.
 */
class ImportController extends Controller
{
    public function __invoke(ImportRequest $request, ImportData $import): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $body = $request->validated();
        $projects = $body['projects'] ?? [];
        $tasks = $body['tasks'] ?? [];

        $result = $import->import($user, ['projects' => $projects, 'tasks' => $tasks]);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'errors' => $result['errors'] ?? [],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'projectsCreated' => $result['projectsCreated'],
            'tasksCreated' => $result['tasksCreated'],
        ], 201);
    }
}
