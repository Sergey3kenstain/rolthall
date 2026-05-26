<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingRulesSeeder extends Seeder
{
    public function run(): void
    {
        $hallId = DB::table('halls')->value('id');
        if (!$hallId) return;

        DB::table('pricing_rules')->where('hall_id', $hallId)->delete();

        $now = now();
        $rules = [
            // ── Почасовая ──────────────────────────────────────────────
            ['booking_format'=>'hourly','day_type'=>'weekday','guest_tier'=>'below30','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>3500,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Почасовая, будни, до 30 чел'],
            ['booking_format'=>'hourly','day_type'=>'weekday','guest_tier'=>'above30','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>5000,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Почасовая, будни, более 30 чел'],
            ['booking_format'=>'hourly','day_type'=>'weekend','guest_tier'=>'below30','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>5000,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Почасовая, выходные, до 30 чел'],
            ['booking_format'=>'hourly','day_type'=>'weekend','guest_tier'=>'above30','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>7000,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Почасовая, выходные, более 30 чел'],

            // ── Событие (Миниивент) ─────────────────────────────────────
            ['booking_format'=>'event','day_type'=>'weekday','guest_tier'=>'any','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>10000,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Событие, будни'],
            ['booking_format'=>'event','day_type'=>'weekend','guest_tier'=>'any','min_hours'=>1,'max_hours'=>null,'price_per_hour'=>15000,'price_per_day'=>null,'prepayment_percent'=>100,'description'=>'Событие, выходные'],

            // ── Весь день ───────────────────────────────────────────────
            ['booking_format'=>'allday','day_type'=>'weekday','guest_tier'=>'any','min_hours'=>0,'max_hours'=>null,'price_per_hour'=>0,'price_per_day'=>100000,'prepayment_percent'=>50,'description'=>'Весь день, без света и звука'],
            ['booking_format'=>'allday','day_type'=>'weekend','guest_tier'=>'any','min_hours'=>0,'max_hours'=>null,'price_per_hour'=>0,'price_per_day'=>150000,'prepayment_percent'=>50,'description'=>'Весь день, со светом и звуком'],
        ];

        foreach ($rules as $rule) {
            DB::table('pricing_rules')->insert(array_merge($rule, [
                'hall_id'    => $hallId,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }
}
