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
        Schema::create('halls', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('area_m2')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->json('equipment')->nullable();       // ["Зеркала","Звук"]
            $table->json('photos')->nullable();          // ["path/1.jpg"]
            $table->unsignedTinyInteger('buffer_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('halls');
    }
};
