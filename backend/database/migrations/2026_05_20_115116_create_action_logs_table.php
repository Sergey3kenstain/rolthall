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
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();  // null = системное действие
            $table->string('role', 50)->nullable();             // developer|landlord|admin|client
            $table->string('action', 100);                      // booking.create, booking.pay, etc.
            $table->string('target_type', 100)->nullable();     // App\Models\Booking
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();                // детали действия
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 191)->nullable();
            // Immutable: только created_at, без updated_at
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'action']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
