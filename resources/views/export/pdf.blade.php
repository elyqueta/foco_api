{!!
-- Relatório de Produtividade Premium da Foco.
-- Design moderno alinhado com a UI da aplicação (cartões, métricas, tipografia limpa).
--}
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }

        @page {
            size: A4;
            margin: 16mm 16mm 18mm 16mm;
            @bottom-center {
                content: "Gerado pela API Foco · " counter(page) " de " counter(pages);
                font-family: 'DejaVu Sans', sans-serif;
                font-size: 7.5px;
                color: #9ca3af;
                margin-top: 6px;
            }
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        /* Marca d'água */
        .watermark {
            position: fixed;
            top: 130mm;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-10deg);
            width: 380px;
            height: auto;
            z-index: 0;
            pointer-events: none;
            opacity: 0.05;
        }

        /* Cabeçalho */
        .header {
            position: relative;
            z-index: 1;
            padding-bottom: 20px;
            margin-bottom: 22px;
            border-bottom: none;
        }

        .logo-top {
            width: 40px;
            height: auto;
            display: block;
            margin: 0 0 12px 0;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 6px 0;
            letter-spacing: -0.5px;
        }

        .header .subtitle {
            font-size: 12px;
            color: #64748b;
            margin: 0 0 14px 0;
            font-weight: 400;
        }

        .header .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 10px;
            color: #475569;
        }

        .header .user-info .avatar {
            width: 24px;
            height: 24px;
            background: #cbd5e1;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .header .user-info .name {
            font-weight: 600;
            color: #334155;
            font-size: 11px;
        }

        .header .user-info .dot {
            width: 3px;
            height: 3px;
            background: #94a3b8;
            border-radius: 50%;
            margin: 0 4px;
        }

        .header .user-info .date {
            color: #64748b;
        }

        /* Métricas de resumo */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 22px;
            z-index: 1;
            position: relative;
        }

        .metric-card {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 10px 8px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }

        .metric-card .icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px auto;
        }

        .metric-card.projects .icon { background: #e0e7ff; color: #2563eb; }
        .metric-card.active .icon { background: #dcfce7; color: #16a34a; }
        .metric-card.tasks .icon { background: #e0e7ff; color: #2563eb; }
        .metric-card.completed .icon { background: #dcfce7; color: #16a34a; }
        .metric-card.pending .icon { background: #fef3c7; color: #d97706; }
        .metric-card.overdue .icon { background: #fee2e2; color: #dc2626; }
        .metric-card.today .icon { background: #fef3c7; color: #d97706; }

        .metric-card .value {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 2px 0;
        }

        .metric-card .label {
            font-size: 7px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin: 0;
        }

        /* Seções */
        .section {
            margin-bottom: 24px;
            z-index: 1;
            position: relative;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title .icon {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .section-title .icon.folder { background: #e0e7ff; color: #2563eb; }
        .section-title .icon.tasks { background: #dcfce7; color: #16a34a; }

        .section-title .count {
            background: #f1f5f9;
            color: #64748b;
            font-size: 7px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 4px;
        }

        .section-subtitle {
            font-size: 8px;
            color: #94a3b8;
            margin: 0 0 4px 0;
            font-weight: 500;
        }

        .section-empty {
            text-align: center;
            color: #94a3b8;
            font-size: 10px;
            font-style: italic;
            padding: 24px;
            border: 1px dashed #e2e8f0;
            border-radius: 8px;
        }

        /* Cartões de projeto */
        .project-card {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 14px 16px 12px 16px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            page-break-inside: avoid;
            position: relative;
            overflow: hidden;
        }

        .project-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 3px;
        }

        .project-card.pending::before { background: #2563eb; }
        .project-card.active::before { background: #10b981; }
        .project-card.done::before { background: #22c55e; }

        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .project-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 2px 0;
        }

        .project-meta {
            display: flex;
            gap: 8px;
            font-size: 7.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 7px;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
        }

        .badge-category { background: #e2e8f0; color: #475569; }
        .badge-status-active { background: #dcfce7; color: #16a34a; }
        .badge-status-paused { background: #f1f5f9; color: #64748b; }
        .badge-status-done { background: #dcfce7; color: #16a34a; }

        .badge-urgency-critical { background: #fee2e2; color: #dc2626; }
        .badge-urgency-high { background: #fed7aa; color: #ea580c; }
        .badge-urgency-medium { background: #fef3c7; color: #d97706; }
        .badge-urgency-low { background: #dcfce7; color: #16a34a; }

        .badge-date { background: #f1f5f9; color: #64748b; }
        .badge-date.no-date { background: #f1f5f9; color: #94a3b8; }

        .project-description {
            font-size: 8.5px;
            color: #475569;
            margin: 0 0 8px 0;
            line-height: 1.35;
        }

        .project-next-step {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 6px;
            padding: 7px 9px;
            margin-top: 6px;
        }

        .project-next-step .label {
            font-size: 6.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin: 0 0 2px 0;
        }

        .project-next-step .text {
            font-size: 9px;
            color: #334155;
            margin: 0;
            font-weight: 600;
        }

        .project-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
            margin-top: -4px;
        }

        .project-progress {
            width: 120px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .project-progress-bar {
            height: 100%;
            background: #2563eb;
            border-radius: 3px;
        }

        .project-progress-text {
            font-size: 7px;
            color: #64748b;
            font-weight: 600;
        }

        /* Cartões de tarefa */
        .task-card {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 10px 12px 8px 12px;
            margin-bottom: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            page-break-inside: avoid;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .task-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .task-icon.category-household { background: #f0fdf4; color: #22c55e; }
        .task-icon.category-personal { background: #eff6ff; color: #2563eb; }
        .task-icon.category-professional { background: #fffbeb; color: #d97706; }

        .task-content {
            flex: 1;
            min-width: 0;
        }

        .task-title {
            font-size: 10px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 3px 0;
            line-height: 1.3;
        }

        .task-description {
            font-size: 7.5px;
            color: #64748b;
            margin: 0 0 4px 0;
            line-height: 1.3;
        }

        .task-meta-row {
            display: flex;
            gap: 10px;
            font-size: 7px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            flex-wrap: wrap;
        }

        .task-badge {
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 6.5px;
            font-weight: 700;
            color: #ffffff;
            white-space: nowrap;
        }

        .task-badge-status-todo { background: #e2e8f0; color: #475569; }
        .task-badge-status-in_progress { background: #dbeafe; color: #2563eb; }
        .task-badge-status-done { background: #dcfce7; color: #16a34a; }
        .task-badge-status-postponed { background: #f1f5f9; color: #64748b; }
        .task-badge-status-expired { background: #fee2e2; color: #dc2626; }

        .task-badge-urgency-critical { background: #fee2e2; color: #dc2626; }
        .task-badge-urgency-high { background: #fed7aa; color: #ea580c; }
        .task-badge-urgency-medium { background: #fef3c7; color: #d97706; }
        .task-badge-urgency-low { background: #dcfce7; color: #16a34a; }

        .task-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }

        .task-date {
            font-size: 7.5px;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
        }

        .task-date.overdue { color: #dc2626; }
        .task-date.today { color: #d97706; }

        .task-duration {
            font-size: 7px;
            color: #64748b;
            font-weight: 600;
        }

        .task-date.no-date { color: #94a3b8; }

        /* Footer */
        .footer {
            margin-top: 24px;
            border-top: 1px solid #f1f5f9;
            padding-top: 8px;
            font-size: 7px;
            color: #94a3b8;
            text-align: center;
            z-index: 1;
            position: relative;
        }

        .footer .brand {
            font-weight: 700;
            color: #0f172a;
        }

        /* Utilitários */
        .no-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    <img src="{{ $logoWatermark }}" class="watermark" alt="Marca d'água">

    <div class="header">
        @if ($logoTop)
            <img src="{{ $logoTop }}" class="logo-top" alt="Logo Foco">
        @endif
        <h1>Relatório de Produtividade</h1>
        <p class="subtitle">Visão geral dos teus projetos e tarefas</p>
        <div class="user-info">
            <span class="avatar"></span>
            <span class="name">{{ $user->name }}</span>
            <span class="dot"></span>
            <span class="date">{{ \Carbon\CarbonImmutable::parse($now)->format('d/m/Y H:i') }}</span>
            @if ($period)
                <span class="dot"></span>
                <span class="date">{{ $period }}</span>
            @endif
        </div>
    </div>

    <!-- Métricas de resumo -->
    <div class="metrics-grid">
        <div class="metric-card projects">
            <div class="icon">📁</div>
            <p class="value">{{ $summary['totalProjects'] }}</p>
            <p class="label">Projetos</p>
        </div>
        <div class="metric-card active">
            <div class="icon">✓</div>
            <p class="value">{{ $summary['activeProjects'] }}</p>
            <p class="label">Projetos ativos</p>
        </div>
        <div class="metric-card tasks">
            <div class="icon">📋</div>
            <p class="value">{{ $summary['totalTasks'] }}</p>
            <p class="label">Tarefas</p>
        </div>
        <div class="metric-card completed">
            <div class="icon">✔</div>
            <p class="value">{{ $summary['completedTasks'] }}</p>
            <p class="label">Concluídas</p>
        </div>
        <div class="metric-card pending">
            <div class="icon">○</div>
            <p class="value">{{ $summary['pendingTasks'] }}</p>
            <p class="label">Pendentes</p>
        </div>
        <div class="metric-card overdue">
            <div class="icon">⚠</div>
            <p class="value">{{ $summary['overdueTasks'] }}</p>
            <p class="label">Atrasadas</p>
        </div>
        <div class="metric-card today">
            <div class="icon">📅</div>
            <p class="value">{{ $summary['dueToday'] }}</p>
            <p class="label">Para hoje</p>
        </div>
    </div>

    <!-- Projetos -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <span class="icon folder">📁</span>
                Projetos
                <span class="count">{{ $projectsCount }}</span>
            </h2>
            <p class="section-subtitle">Projetos ativos no teu momento</p>
        </div>
        @if ($projects->isEmpty())
            <div class="section-empty">Sem projetos cadastrados.</div>
        @else
            @foreach ($projects as $project)
                <div class="project-card {{ $project['status'] ?? 'pending' }}">
                    <div class="project-header">
                        <span class="project-name">{{ $project['name'] }}</span>
                        @if ($project['urgency'])
                            <span class="badge badge-urgency-{{ $project['urgency'] }}">{{ ucfirst($project['urgency']) }}</span>
                        @endif
                    </div>
                    <div class="project-meta">
                        @if ($project['category'])
                            <span class="badge badge-category">{{ ucfirst($project['category']) }}</span>
                        @endif
                        @if ($project['status'])
                            <span class="badge badge-status-{{ $project['status'] }}">{{ ucfirst($project['status']) }}</span>
                        @endif
                        @if ($project['dueDate'])
                            <span class="badge badge-date">{{ \Carbon\CarbonImmutable::parse($project['dueDate'])->format('d M. Y') }}</span>
                        @else
                            <span class="badge badge-date no-date">Sem prazo</span>
                        @endif
                    </div>
                    @if ($project['description'] && trim($project['description']) !== '')
                        <p class="project-description">{{ $project['description'] }}</p>
                    @endif
                    @if ($project['nextStep'] && trim($project['nextStep']) !== '')
                        <div class="project-next-step">
                            <p class="label">Próximo passo</p>
                            <p class="text">{{ $project['nextStep'] }}</p>
                        </div>
                    @endif
                    <div class="project-right">
                        <div class="project-progress">
                            <div class="project-progress-bar" style="width: 50%;"></div>
                        </div>
                        <p class="project-progress-text">50%</p>
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <!-- Tarefas -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <span class="icon tasks">✓</span>
                Tarefas
                <span class="count">{{ $tasksCount }}</span>
            </h2>
            <p class="section-subtitle">Tarefas para manter o foco</p>
        </div>
        @if ($tasks->isEmpty())
            <div class="section-empty">Sem tarefas cadastradas.</div>
        @else
            @foreach ($tasks as $task)
                <?php
                    $dueDate = $task['dueDate'] ?? null;
                    $isOverdue = false;
                    $isToday = false;
                    if ($dueDate) {
                        $today = \Carbon\CarbonImmutable::now()->format('Y-m-d');
                        $isOverdue = $dueDate < $today && $task['status'] !== 'done';
                        $isToday = $dueDate === $today && $task['status'] !== 'done';
                    }
                ?>
                <div class="task-card">
                    <div class="task-icon category-{{ $task['category'] ?? 'personal' }}"></div>
                    <div class="task-content">
                        <span class="task-title">{{ $task['title'] }}</span>
                        @if ($task['description'] && trim($task['description']) !== '')
                            <p class="task-description">{{ $task['description'] }}</p>
                        @endif
                        <div class="task-meta-row">
                            @if ($task['category'])
                                <span class="task-badge">{{ ucfirst($task['category']) }}</span>
                            @endif
                            @if ($task['status'])
                                <span class="task-badge task-badge-status-{{ $task['status'] }}">{{ ucfirst($task['status']) }}</span>
                            @endif
                            @if ($task['urgency'])
                                <span class="task-badge task-badge-urgency-{{ $task['urgency'] }}">{{ ucfirst($task['urgency']) }}</span>
                            @endif
                            @if ($task['projectName'] ?? null)
                                <span class="task-badge" style="background:#f1f5f9;color:#64748b;">{{ $task['projectName'] }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="task-right">
                        @if ($dueDate)
                            <span class="task-date {{ $isOverdue ? 'overdue' : '' }} {{ $isToday ? 'today' : '' }}">
                                {{ \Carbon\CarbonImmutable::parse($dueDate)->format('d M. Y') }}
                                @if ($isOverdue)
                                    · Atrasada
                                @elseif ($isToday)
                                    · Prazo hoje
                                @endif
                            </span>
                        @else
                            <span class="task-date no-date">Sem prazo</span>
                        @endif
                        @if ($task['estimateMinutes'])
                            <span class="task-duration">{{ $task['estimateMinutes'] }} min</span>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <div class="footer">
        <span class="brand">Foco</span> · Gerado pela API Foco · {{ \Carbon\CarbonImmutable::parse($now)->format('d/m/Y H:i:s') }} · Backup pessoal do utilizador
    </div>
</body>
</html>
