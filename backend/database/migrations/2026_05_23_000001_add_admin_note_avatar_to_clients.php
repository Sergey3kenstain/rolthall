<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('avatar_url', 500)->nullable()->after('telegram_username');
            $table->text('admin_note')->nullable()->after('blacklist_reason');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'admin_note']);
        });
    }
};
