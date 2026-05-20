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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained()->cascadeOnDelete();
            $table->enum('day_type', ['weekday', 'weekend']);  // weekday=Пн-Пт, weekend=Сб-Вс
            $table->unsignedTinyInteger('min_hours');
            $table->unsignedTinyInteger('max_hours')->nullable(); // null = без ограничения
            $table->unsignedInteger('price_per_hour');            // в рублях
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hall_id', 'day_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
