<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices de suporte ao matching de categoria por nome
     * (`tasks.category`/`projects.category` = `categories.name`), usados
     * pelas contagens com withCount e pela reatribuição do RemoveCategory.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['user_id', 'category']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->index(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'category']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'category']);
        });
    }
};
