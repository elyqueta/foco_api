<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AppDataResource;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportação de dados (doc 11 §"Exportação"): devolve o estado do utilizador
 * no formato `AppData` compatível com `foco-backup.json`.
 *
 * Query `?format=pdf` devolve um relatório premium de produtividade (PDF A4).
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
     * Gera o relatório premium de produtividade em PDF (A4).
     */
    private function exportPdf(User $user, array $data, ?string $from, ?string $to): Response
    {
        $logoTop = $this->svgToBase64(public_path('brands/foco-login-art.svg'));
        $logoWatermark = $this->svgToBase64(public_path('brands/foco-logo-horizontal.svg'));

        $period = '';
        if ($from || $to) {
            $period = 'Período: '.($from ?: 'início').' → '.($to ?: 'presente');
        }

        $summary = $this->computeSummary($data, $user);

        $pdf = Pdf::loadView('export.pdf', [
            'user' => $user,
            'projects' => collect($data['projects'] ?? []),
            'tasks' => collect($data['tasks'] ?? []),
            'projectsCount' => count($data['projects'] ?? []),
            'tasksCount' => count($data['tasks'] ?? []),
            'summary' => $summary,
            'logoTop' => $logoTop,
            'logoWatermark' => $logoWatermark,
            'period' => $period,
            'now' => CarbonImmutable::now(),
        ])->setPaper('a4', 'portrait');

        $suffix = $period ? '-'.str_replace([' ', '→', ':', ''], '', $period) : '';
        $filename = 'foco-relatorio-'.now()->format('Y-m-d').$suffix.'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Calcula as métricas de resumo de produtividade.
     *
     * @return array<string, int|string>
     */
    private function computeSummary(array $data, User $user): array
    {
        $projects = $data['projects'] ?? [];
        $tasks = $data['tasks'] ?? [];

        $totalProjects = count($projects);
        $activeProjects = count(array_filter($projects, fn (array $p): bool => ($p['status'] ?? '') === 'active'));
        $totalTasks = count($tasks);
        $completedTasks = count(array_filter($tasks, fn (array $t): bool => ($t['status'] ?? '') === 'done'));
        $pendingTasks = $totalTasks - $completedTasks;

        $today = CarbonImmutable::now()->format('Y-m-d');
        $dueToday = count(array_filter($tasks, fn (array $t): bool => ($t['dueDate'] ?? null) === $today && ($t['status'] ?? '') !== 'done'));

        $overdueTasks = 0;
        foreach ($tasks as $task) {
            $dueDate = $task['dueDate'] ?? null;
            $status = $task['status'] ?? '';
            if ($dueDate !== null && $dueDate < $today && $status !== 'done') {
                $overdueTasks++;
            }
        }

        return [
            'totalProjects' => $totalProjects,
            'activeProjects' => $activeProjects,
            'totalTasks' => $totalTasks,
            'completedTasks' => $completedTasks,
            'pendingTasks' => $pendingTasks,
            'dueToday' => $dueToday,
            'overdueTasks' => $overdueTasks,
        ];
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
