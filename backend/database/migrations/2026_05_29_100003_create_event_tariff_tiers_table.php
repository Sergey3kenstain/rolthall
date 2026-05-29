<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tariff_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained('event_tariffs')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('price'); // рублей
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tariff_tiers');
    }
};
