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
    \App\Models\Booking::where('status', \App\Models\Booking::STATUS_CONFIRMED)
        ->where('date', '<', now()->toDateString())
        ->orWhere(function ($q) {
            $q->where('status', \App\Models\Booking::STATUS_CONFIRMED)
              ->where('date', now()->toDateString())
              ->where('time_end', '<', now()->format('H:i:s'));
        })
        ->update(['status' => \App\Models\Booking::STATUS_COMPLETED]);
})->everyFifteenMinutes()->name('bookings:complete')->withoutOverlapping();
