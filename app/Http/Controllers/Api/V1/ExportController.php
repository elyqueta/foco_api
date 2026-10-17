<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AppDataResource;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportação de dados (doc 11 §"Exportação"): devolve o estado do utilizador
 * no formato `AppData` compatível com `foco-backup.json`.
 *
 * Query `?format=pdf` devolve um PDF com logo, marca d'água e tabelas.
 * Cabeçalho `Content-Disposition: attachment; filename="foco-backup-YYYY-MM-DD.json|pdf"`.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        $resource = new AppDataResource($user);
        $data = $resource->resolve();

        $format = $request->query('format', 'json');

        if ($format === 'pdf') {
            return $this->exportPdf($user, $data);
        }

        $filename = 'foco-backup-'.now()->format('Y-m-d').'.json';

        return response()->json($data)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
    }

    /**
     * Gera o PDF de exportação com logo, marca d'água e tabelas.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function exportPdf(User $user, array $data): Response
    {
        $pdf = Pdf::loadView('export.pdf', [
            'user' => $user,
            'projects' => collect($data['projects'] ?? []),
            'tasks' => collect($data['tasks'] ?? []),
            'projectsCount' => count($data['projects'] ?? []),
            'tasksCount' => count($data['tasks'] ?? []),
        ])->setPaper('a4', 'portrait');

        $filename = 'foco-backup-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}
