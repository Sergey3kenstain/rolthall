<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HallSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $hall = \App\Models\Hall::create([
            'name'           => 'Зал А',
            'description'    => 'Профессиональное танцевальное пространство 346 м². Зеркала, профессиональный звук и свет, раздевалки, зона ожидания.',
            'area_m2'        => 346,
            'capacity'       => 80,
            'equipment'      => ['Зеркала', 'Профессиональный звук', 'Сценический свет', 'Раздевалки', 'Зона ожидания'],
            'photos'         => [],
            'buffer_minutes' => 0,
            'is_active'      => true,
            'sort_order'     => 1,
        ]);

        // ── Тарифы Пн–Пт ────────────────────────────────────────────────
        $weekday = [
            ['min_hours' => 1, 'max_hours' => null, 'price_per_hour' => 3500],  // тренировки
        ];

        // ── Тарифы Сб–Вс ────────────────────────────────────────────────
        $weekend = [
            ['min_hours' => 1, 'max_hours' => null, 'price_per_hour' => 5000],  // тренировки
        ];

        foreach ($weekday as $rule) {
            $hall->pricingRules()->create(array_merge($rule, ['day_type' => 'weekday', 'is_active' => true]));
        }

        foreach ($weekend as $rule) {
            $hall->pricingRules()->create(array_merge($rule, ['day_type' => 'weekend', 'is_active' => true]));
        }

        $this->command->info("Создан зал: {$hall->name} (ID: {$hall->id}) с " . $hall->pricingRules()->count() . " тарифами");
    }
}
