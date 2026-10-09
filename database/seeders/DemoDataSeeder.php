<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ActivityLog;
use App\Actions\Projects\CreateProject;
use App\Actions\Tasks\CreateTask;
use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed demo data mirroring the front-end's reference state (§23).
     *
     * Only runs for the given user when they have no projects and no tasks.
     * Dates are relative to today in the user's timezone.
     */
    public function run(?User $user = null): void
    {
        if ($user === null) {
            $user = User::whereEmail('admin@todo.ao')->first();

            if ($user === null) {
                return;
            }
        }

        if ($user->projects()->exists() || $user->tasks()->exists()) {
            return;
        }

        $today = $user->today();
        $todayStr = $today->format('Y-m-d');

        // Project 1: Loja Nerd
        $lojaNerd = app(CreateProject::class)->handle($user, [
            'name' => 'Loja Nerd',
            'description' => 'Marca de acessórios tech para geeks e gamers.',
            'category' => 'personal',
            'urgency' => 'high',
            'canPostpone' => true,
            'dueDate' => $todayStr,
            'nextStep' => 'Definir catálogo inicial de 10 produtos',
            'color' => '#3FBF9A',
        ]);

        // Project 2: destino-mussulo
        $mussulo = app(CreateProject::class)->handle($user, [
            'name' => 'destino-mussulo',
            'description' => 'Plataforma de reservas para o Mussulo.',
            'category' => 'professional',
            'urgency' => 'critical',
            'canPostpone' => false,
            'dueDate' => $todayStr,
            'nextStep' => 'Integrar API real no backend',
            'color' => '#6C5CE7',
        ]);

        // Task 1: Integrar API real no destino-mussulo
        app(CreateTask::class)->handle($user, [
            'title' => 'Integrar API real no destino-mussulo',
            'description' => '',
            'category' => 'professional',
            'urgency' => 'critical',
            'canPostpone' => false,
            'dueDate' => $todayStr,
            'estimateMinutes' => 90,
            'tags' => ['angular', 'api'],
            'nextStep' => 'Trocar mock service por HttpClient',
            'projectId' => $mussulo->id,
        ]);

        // Task 2: Definir catálogo inicial (in_progress)
        $catalogo = app(CreateTask::class)->handle($user, [
            'title' => 'Definir catálogo inicial',
            'description' => '',
            'category' => 'personal',
            'urgency' => 'high',
            'canPostpone' => true,
            'dueDate' => $todayStr,
            'estimateMinutes' => 60,
            'tags' => ['produto'],
            'nextStep' => 'Pesquisar fornecedores de acessórios',
            'projectId' => $lojaNerd->id,
        ]);

        $catalogo->update([
            'status' => TaskStatus::InProgress,
        ]);

        app(ActivityLog::class)->record(
            user: $user,
            subject: $catalogo,
            type: ActivityType::StatusChanged,
            message: 'Estado alterado para Em curso',
        );

        // Task 3: Compras do supermercado
        app(CreateTask::class)->handle($user, [
            'title' => 'Compras do supermercado',
            'description' => '',
            'category' => 'household',
            'urgency' => 'medium',
            'canPostpone' => true,
            'dueDate' => $todayStr,
            'estimateMinutes' => 30,
            'tags' => ['casa'],
            'nextStep' => 'Fazer lista antes de sair',
            'projectId' => $lojaNerd->id,
        ]);

        // Task 4: Ligar para a mãe
        app(CreateTask::class)->handle($user, [
            'title' => 'Ligar para a mãe',
            'description' => '',
            'category' => 'personal',
            'urgency' => 'low',
            'canPostpone' => true,
            'dueDate' => null,
            'estimateMinutes' => 15,
            'tags' => [],
            'nextStep' => '',
            'projectId' => $lojaNerd->id,
        ]);
    }
}
