<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_dispatch_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('channel', 10); // in_app|email
            $table->string('subject_type', 10)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('dedupe_key', 120);
            $table->string('status', 10)->default('pending'); // pending|sent|failed|skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'type', 'channel', 'subject_id', 'dedupe_key']);
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_log');
    }
};
