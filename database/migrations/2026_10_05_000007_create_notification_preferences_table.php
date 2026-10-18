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
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->unsignedTinyInteger('digest_hour')->default(8); // 0-23, local hour
            $table->unsignedSmallInteger('due_soon_hours')->default(24);
            $table->unsignedSmallInteger('due_imminent_minutes')->default(60);
            $table->unsignedTinyInteger('timer_long_hours')->default(4);
            $table->jsonb('types')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
