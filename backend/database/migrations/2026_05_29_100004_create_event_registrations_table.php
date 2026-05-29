<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('phone');
            $table->string('tg_user_id')->nullable();
            $table->string('max_user_id')->nullable();
            $table->json('fields_data')->nullable();            // ответы на кастомные поля
            $table->foreignId('tariff_id')->nullable()->constrained('event_tariffs')->nullOnDelete();
            $table->string('selected_option')->nullable();      // выбранный танцор
            $table->enum('payment_status', ['free', 'pending', 'paid', 'failed'])->default('free');
            $table->unsignedInteger('payment_amount')->default(0);
            $table->string('payment_order_id')->nullable()->unique();
            $table->string('tbank_payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->boolean('ticket_sent')->default(false);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
