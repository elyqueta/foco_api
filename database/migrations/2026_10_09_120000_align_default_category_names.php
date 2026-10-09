<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alinha o nome das categorias padrão com a chave (`name_key`):
     * `professional`, `personal`, `household`. Tarefas e projetos já guardam
     * estas strings (o `category` passa a ser comparado pelo nome), pelo que
     * a conversão não exige mexer em tasks/projects.
     */
    public function up(): void
    {
        DB::table('categories')
            ->where('is_default', true)
            ->update(['name' => DB::raw('name_key')]);
    }

    /**
     * Reverte para os nomes de apresentação usados no arranque da Fase 3.
     */
    public function down(): void
    {
        // Rollback seguro só é possível repropondo a imagem anterior: reverter
        // os nomes aqui deixaria tasks/projects (que guardam o nome canónico
        // escrito desde o deploy) a apontar para categorias inexistentes.
        // A reatribuição de dados pertence a uma migration dedicada, não a
        // este rollback.
    }
};
