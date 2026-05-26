<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->json('cms')->nullable()->after('photos');
        });

        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->string('description', 191)->nullable()->after('day_type');
        });
    }

    public function down(): void
    {
        Schema::table('halls', function (Blueprint $table) {
            $table->dropColumn('cms');
        });
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
