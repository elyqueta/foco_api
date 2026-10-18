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
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->default('');
            $table->string('category', 60);
            $table->string('urgency', 10)->default('medium');
            $table->string('status', 15)->default('todo');
            $table->boolean('can_postpone')->default(true);
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->string('next_step', 255)->default('');
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedBigInteger('tracked_seconds')->default(0);
            $table->timestampTz('first_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->unsignedSmallInteger('postponed_count')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_date']);
            $table->index(['project_id']);
        });

        // Set a server-side default of '[]' on PostgreSQL so inserts without tags work.
        // SQLite does not support ALTER ... SET DEFAULT for JSON, so we skip it there.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tasks ALTER COLUMN tags SET DEFAULT '[]'::jsonb");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
