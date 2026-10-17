<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AppDataResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exportação de dados (doc 11 §"Exportação"): devolve o estado do utilizador
 * no formato `AppData` compatível com `foco-backup.json`.
 *
 * A API expõe apenas JSON. Não há exportação para PDF.
 *
 * Cabeçalho `Content-Disposition: attachment; filename="foco-backup-YYYY-MM-DD.json"`.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $from = $request->query('from');
        $to = $request->query('to');

        $resource = new AppDataResource($user, $from, $to);
        $data = $resource->resolve();

        $filename = 'foco-backup-'.now()->format('Y-m-d').'.json';

        return response()->json($data)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
    }
}
