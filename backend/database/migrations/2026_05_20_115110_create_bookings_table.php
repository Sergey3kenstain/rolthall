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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hall_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->time('time_start');
            $table->time('time_end');
            $table->unsignedTinyInteger('duration_hours');

            $table->enum('format', ['single', 'event', 'monthly'])->default('single');
            $table->enum('status', [
                'draft',
                'hold',
                'pending_payment',
                'paid',
                'confirmed',
                'completed',
                'cancelled',
            ])->default('draft');

            $table->unsignedInteger('total_amount');       // в рублях
            $table->unsignedInteger('prepayment_amount');  // 50% от total

            $table->string('payment_token', 191)->nullable();   // CP/TBank токен карты
            $table->string('transaction_id', 100)->nullable();  // ID транзакции

            $table->timestamp('hold_expires_at')->nullable();   // когда истекает hold

            // Согласия (логируем при оформлении)
            $table->boolean('consent_offer')->default(false);
            $table->boolean('consent_policy')->default(false);
            $table->boolean('consent_refund')->default(false);

            $table->text('notes')->nullable();        // комментарий клиента
            $table->text('admin_notes')->nullable();  // заметки админа

            $table->timestamps();

            $table->index(['hall_id', 'date', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'hold_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
