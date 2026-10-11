<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tokens Sanctum expirados são removidos diariamente (usado também no tick).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Retenção das notificações in-app: lidas > 30 dias e todas > 90 dias
// (doc 09 tarefa 9.6; chamado também pelo tick da Fase 10).
Schedule::command('foco:prune-notifications')->daily();
