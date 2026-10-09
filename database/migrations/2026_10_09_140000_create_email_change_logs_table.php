<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de mudanças de email: quem mudou, de que email para qual,
     * quando e de que IP (controlo de alterações de conta).
     */
    public function up(): void
    {
        Schema::create('email_change_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_email', 255);
            $table->string('new_email', 255);
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_logs');
    }
};
