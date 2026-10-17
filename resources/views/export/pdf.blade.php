{!!
-- PDF de exportação de dados do utilizador (backup).
-- Exibe logo no topo, dados do user, marca d'água e tabelas de projetos e tarefas.
--}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 20px;
            position: relative;
        }
        .watermark {
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-15deg);
            width: 600px;
            height: auto;
            z-index: 0;
            pointer-events: none;
            opacity: 0.10;
        }
        .header {
            position: relative;
            z-index: 1;
            margin-bottom: 20px;
            border-bottom: 2px solid #3FBF9A;
            padding-bottom: 10px;
        }
        .logo-top {
            width: 200px;
            height: auto;
            display: block;
            margin-bottom: 8px;
        }
        .header-text {
            margin-top: 10px;
            font-size: 14px;
            color: #555;
        }
        .header-text strong { color: #333; }
        .section {
            margin-top: 25px;
            position: relative;
            z-index: 1;
        }
        .section-title {
            background: #3FBF9A;
            color: white;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 4px;
            display: inline-block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10px;
        }
        th {
            background: #f0f0f0;
            color: #333;
            padding: 6px 8px;
            text-align: left;
            border: 1px solid #ccc;
            font-weight: bold;
        }
        td {
            padding: 5px 8px;
            border: 1px solid #ccc;
            vertical-align: top;
        }
        tr:nth-child(even) td { background: #fafafa; }
        .empty {
            text-align: center;
            color: #888;
            font-style: italic;
            padding: 20px;
        }
        .footer {
            margin-top: 30px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
            font-size: 9px;
            color: #888;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            color: white;
        }
        .badge-critical { background: #e74c3c; }
        .badge-high { background: #e67e22; }
        .badge-medium { background: #f1c40f; }
        .badge-low { background: #2ecc71; }
        .badge-todo { background: #3498db; }
        .badge-in_progress { background: #9b59b6; }
        .badge-done { background: #27ae60; }
        .badge-postponed { background: #95a5a6; }
        .badge-expired { background: #c0392b; }
    </style>
</head>
<body>
    <img src="{{ $logoWatermark }}" class="watermark" alt="Marca d'água">

    <div class="header">
        @if ($logoTop)
            <img src="{{ $logoTop }}" class="logo-top" alt="Logo Foco">
        @endif
        <div class="header-text">
            <strong>Foco · {{ $user->name }}</strong><br>
            {{ \Carbon\CarbonImmutable::now()->format('d/m/Y H:i') }} ·
            @if ($period)
                {{ $period }}
            @else
                Backup de dados do utilizador
            @endif
        </div>
    </div>

    <div class="section">
        <span class="section-title">Projetos ({{ $projectsCount }})</span>
        @if ($projects->isEmpty())
            <div class="empty">Sem projetos.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Urgência</th>
                        <th>Estado</th>
                        <th>Prazo</th>
                        <th>Próximo passo</th>
                        <th>Cor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $project)
                        <tr>
                            <td>{{ $project['name'] }}</td>
                            <td>{{ $project['category'] }}</td>
                            <td><span class="badge badge-{{ $project['urgency'] ?? 'medium' }}">{{ ucfirst($project['urgency'] ?? 'medium') }}</span></td>
                            <td><span class="badge badge-{{ $project['status'] ?? 'active' }}">{{ ucfirst($project['status'] ?? 'active') }}</span></td>
                            <td>{{ $project['dueDate'] ?? '—' }}</td>
                            <td>{{ $project['nextStep'] ?? '—' }}</td>
                            <td>{{ $project['color'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section">
        <span class="section-title">Tarefas ({{ $tasksCount }})</span>
        @if ($tasks->isEmpty())
            <div class="empty">Sem tarefas.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Projeto</th>
                        <th>Categoria</th>
                        <th>Urgência</th>
                        <th>Estado</th>
                        <th>Prazo</th>
                        <th>Est. (min)</th>
                        <th>Tempo (s)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tasks as $task)
                        <tr>
                            <td>{{ $task['title'] }}</td>
                            <td>{{ $task['projectName'] ?? '—' }}</td>
                            <td>{{ $task['category'] }}</td>
                            <td><span class="badge badge-{{ $task['urgency'] ?? 'medium' }}">{{ ucfirst($task['urgency'] ?? 'medium') }}</span></td>
                            <td><span class="badge badge-{{ $task['status'] ?? 'todo' }}">{{ ucfirst($task['status'] ?? 'todo') }}</span></td>
                            <td>{{ $task['dueDate'] ?? '—' }}</td>
                            <td>{{ $task['estimateMinutes'] ?? '—' }}</td>
                            <td>{{ $task['trackedSeconds'] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="footer">
        Gerado pela API Foco · {{ \Carbon\CarbonImmutable::now()->format('d/m/Y H:i:s') }} · Backup pessoal do utilizador
    </div>
</body>
</html>
