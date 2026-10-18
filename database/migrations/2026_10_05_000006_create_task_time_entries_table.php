<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_time_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_reason', 15)->nullable(); // pause|auto_pause|complete|postpone|reset
            $table->timestampTz('created_at');

            $table->index(['task_id', 'started_at']);
        });

        // Partial unique indexes — enforce at most one open entry per task and per user.
        // Works in both PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX task_time_entries_one_open_per_task ON task_time_entries (task_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX task_time_entries_one_open_per_user ON task_time_entries (user_id) WHERE ended_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS task_time_entries_one_open_per_user');
        DB::statement('DROP INDEX IF EXISTS task_time_entries_one_open_per_task');

        Schema::dropIfExists('task_time_entries');
    }
};
