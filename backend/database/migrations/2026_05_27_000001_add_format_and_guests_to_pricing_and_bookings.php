<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── pricing_rules: добавляем booking_format и guest_tier ─────────
        Schema::table('pricing_rules', function (Blueprint $table) {
            // hourly=Почасовая, event=Событие, allday=Весь день
            $table->enum('booking_format', ['hourly', 'event', 'allday'])
                  ->default('hourly')
                  ->after('hall_id');
            // any=без разбивки, below30=до 30 чел, above30=больше 30 чел
            $table->enum('guest_tier', ['any', 'below30', 'above30'])
                  ->default('any')
                  ->after('day_type');
            // для allday: цена за день целиком (не за час)
            $table->unsignedInteger('price_per_day')->nullable()->after('price_per_hour');
            // предоплата в %: 100 = полная оплата, 50 = 50%
            $table->unsignedTinyInteger('prepayment_percent')->default(100)->after('price_per_day');
        });

        // ── bookings: добавляем guest_count, переименовываем формат ──────
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('guest_count')->default(1)->after('duration_hours');
        });

        // Мигрируем старые значения format: single→hourly, monthly→hourly
        DB::table('bookings')->where('format', 'single')->update(['format' => 'hourly']);
        DB::table('bookings')->where('format', 'monthly')->update(['format' => 'hourly']);

        // Меняем enum bookings.format на новые значения
        DB::statement("ALTER TABLE bookings MODIFY format ENUM('hourly','event','allday') NOT NULL DEFAULT 'hourly'");
    }

    public function down(): void
    {
        // Откат bookings
        DB::statement("ALTER TABLE bookings MODIFY format ENUM('single','event','monthly') NOT NULL DEFAULT 'single'");

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('guest_count');
        });

        // Откат pricing_rules
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn(['booking_format', 'guest_tier', 'price_per_day', 'prepayment_percent']);
        });
    }
};
