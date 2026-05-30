<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_fields', function (Blueprint $table) {
            $table->string('dep_field')->nullable()->after('depends_on_tariff');
            $table->string('dep_value')->nullable()->after('dep_field');
            $table->string('placeholder')->nullable()->after('label');
            $table->string('mask_pattern', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_fields', function (Blueprint $table) {
            $table->dropColumn(['dep_field', 'dep_value', 'placeholder']);
        });
    }
};
