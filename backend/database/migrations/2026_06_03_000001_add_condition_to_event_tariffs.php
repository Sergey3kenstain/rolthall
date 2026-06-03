<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tariffs', function (Blueprint $table) {
            $table->string('condition_field')->nullable()->after('sort_order');
            $table->string('condition_value')->nullable()->after('condition_field');
        });
    }

    public function down(): void
    {
        Schema::table('event_tariffs', function (Blueprint $table) {
            $table->dropColumn(['condition_field', 'condition_value']);
        });
    }
};
