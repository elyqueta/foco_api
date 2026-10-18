<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notifications.notifiable_id` nasceu como `uuid` (`uuidMorphs` da Fase 2),
 * mas o `User` tem chave inteira: qualquer `$user->notifications()` lançava
 * `22P02 invalid input syntax for type uuid: "1"` no PostgreSQL — a stack de
 * notificações da Fase 9 nunca funcionaria.
 *
 * A tabela está sem linhas (nada a escreve antes desta fase), por isso a
 * conversão é segura. O índice volta a ser criado pelo `morphs()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropMorphs('notifiable');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            // `morphs` cria `notifiable_id` como string (compatível com o id
            // inteiro do User, igual à migration padrão do Laravel).
            $table->morphs('notifiable');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropMorphs('notifiable');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuidMorphs('notifiable');
        });
    }
};
