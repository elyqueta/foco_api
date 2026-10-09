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
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->default('');
            $table->string('category', 60);
            $table->string('urgency', 10)->default('medium');
            $table->string('status', 10)->default('active');
            $table->boolean('can_postpone')->default(true);
            $table->date('due_date')->nullable();
            $table->string('next_step', 255)->default('');
            $table->string('color', 7)->default('#6C5CE7');
            $table->timestampTz('overdue_notified_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
