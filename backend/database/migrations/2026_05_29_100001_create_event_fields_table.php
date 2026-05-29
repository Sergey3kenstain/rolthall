<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('label');
            $table->string('field_type')->default('text'); // text, phone, email, select, textarea, checkbox
            $table->string('slug');
            $table->boolean('has_mask')->default(false);
            $table->string('mask_pattern')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('options')->nullable(); // для select: ["Танцор А", "Танцор Б"]
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_fields');
    }
};
