<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_password', 32)->nullable()->after('email');
            $table->bigInteger('tg_credentials_msg_id')->nullable()->after('client_password');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['client_password', 'tg_credentials_msg_id']);
        });
    }
};
