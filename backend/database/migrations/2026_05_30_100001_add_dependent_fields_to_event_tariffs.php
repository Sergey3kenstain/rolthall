<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tariffs', function (Blueprint $table) {
            $table->json('dependent_fields')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('event_tariffs', function (Blueprint $table) {
            $table->dropColumn('dependent_fields');
        });
    }
};
