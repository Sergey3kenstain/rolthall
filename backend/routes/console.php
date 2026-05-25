<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Снимаем hold каждую минуту
Schedule::command('bookings:release-holds')->everyMinute()->withoutOverlapping();

// Переводим оплаченные брони в completed после окончания
Schedule::call(function () {
    \App\Models\Booking::whereIn('status', [
            \App\Models\Booking::STATUS_PAID,
            \App\Models\Booking::STATUS_CONFIRMED,
        ])
        ->where(function ($q) {
            $q->where('date', '<', now()->toDateString())
              ->orWhere(function ($q2) {
                  $q2->where('date', now()->toDateString())
                     ->where('time_end', '<', now()->format('H:i:s'));
              });
        })
        ->update(['status' => \App\Models\Booking::STATUS_COMPLETED]);
})->everyFifteenMinutes()->name('bookings:complete')->withoutOverlapping();
