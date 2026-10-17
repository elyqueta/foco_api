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
 * Query `?from=YYYY-MM-DD&to=YYYY-MM-DD` filtra projetos e tarefas por prazo.
 * Cabeçalho `Content-Disposition: attachment; filename="foco-backup-YYYY-MM-DD.json|pdf"`.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        $from = $request->query('from');
        $to = $request->query('to');

        $resource = new AppDataResource($user, $from, $to);
        $data = $resource->resolve();

        $format = $request->query('format', 'json');

        if ($format === 'pdf') {
            return $this->exportPdf($user, $data, $from, $to);
        }

        $filename = 'foco-backup-'.now()->format('Y-m-d').'.json';

        return response()->json($data)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
    }

    /**
     * Gera o PDF de exportação com logo, marca d'água e tabelas.
     */
    private function exportPdf(User $user, array $data, ?string $from, ?string $to): Response
    {
        $logoTop = $this->svgToBase64(public_path('brands/foco-login-art.svg'));
        $logoWatermark = $this->svgToBase64(public_path('brands/foco-logo-horizontal.svg'));

        $period = '';
        if ($from || $to) {
            $period = 'Período: '.($from ?: 'início').' → '.($to ?: 'hoje');
        }

        $pdf = Pdf::loadView('export.pdf', [
            'user' => $user,
            'projects' => collect($data['projects'] ?? []),
            'tasks' => collect($data['tasks'] ?? []),
            'projectsCount' => count($data['projects'] ?? []),
            'tasksCount' => count($data['tasks'] ?? []),
            'logoTop' => $logoTop,
            'logoWatermark' => $logoWatermark,
            'period' => $period,
        ])->setPaper('a4', 'portrait');

        $suffix = $period ? '-'.str_replace([' ', '→', ':', ''], '', $period) : '';
        $filename = 'foco-backup-'.now()->format('Y-m-d').$suffix.'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Converte um ficheiro SVG para data URI base64.
     */
    private function svgToBase64(string $path): ?string
    {
        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $base64 = base64_encode($content);

        return 'data:image/svg+xml;base64,'.$base64;
    }
}
