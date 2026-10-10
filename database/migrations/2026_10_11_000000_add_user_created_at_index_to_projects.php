<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A listagem de projetos ordena por `created_at` decrescente com
     * desempate no nome — sem este índice o PostgreSQL faz Seq Scan + Sort
     * por utilizador. Os índices existentes cobrem (user_id, status),
     * (user_id, due_date) e (user_id, category), mas não `created_at`.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
