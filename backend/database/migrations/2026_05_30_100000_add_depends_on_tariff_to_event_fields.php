<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_fields', function (Blueprint $table) {
            $table->string('depends_on_tariff')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('event_fields', function (Blueprint $table) {
            $table->dropColumn('depends_on_tariff');
        });
    }
};
