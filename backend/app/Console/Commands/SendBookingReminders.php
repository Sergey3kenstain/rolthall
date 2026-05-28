<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:send-reminders')]
#[Description('Отправляет напоминания клиентам — за 3ч (почасовая/событие) и накануне в 20:00 (весь день)')]
class SendBookingReminders extends Command
{
    public function handle(NotificationService $notify): void
    {
        $now = Carbon::now();

        // Почасовые и события: напоминание за 3 часа до начала (окно ±1 мин)
        $targetTime     = $now->copy()->addHours(3);
        $windowStart    = $targetTime->copy()->subMinute()->format('H:i:s');
        $windowEnd      = $targetTime->copy()->addMinute()->format('H:i:s');

        $hourly = Booking::whereIn('status', [Booking::STATUS_PAID, Booking::STATUS_CONFIRMED])
            ->whereIn('format', ['hourly', 'event'])
            ->where('date', $now->toDateString())
            ->whereBetween('time_start', [$windowStart, $windowEnd])
            ->with(['client.user', 'hall'])
            ->get();

        foreach ($hourly as $booking) {
            $chatId = $booking->client->user->telegram_chat_id ?? null;
            if (!$chatId) continue;

            $notify->notifyClientReminder3h($chatId, [
                'hall_name'  => $booking->hall->name,
                'date'       => $booking->getDateFormatted(),
                'time_start' => substr($booking->time_start, 0, 5),
                'time_end'   => substr($booking->time_end,   0, 5),
            ]);
        }

        // Весь день: напоминание накануне ровно в 20:00
        if ($now->format('H:i') === '20:00') {
            $tomorrow = $now->copy()->addDay()->toDateString();

            $allday = Booking::whereIn('status', [Booking::STATUS_PAID, Booking::STATUS_CONFIRMED])
                ->where('format', 'allday')
                ->where('date', $tomorrow)
                ->with(['client.user', 'hall'])
                ->get();

            foreach ($allday as $booking) {
                $chatId = $booking->client->user->telegram_chat_id ?? null;
                if (!$chatId) continue;

                $notify->notifyClientReminder3h($chatId, [
                    'hall_name'  => $booking->hall->name,
                    'date'       => $booking->getDateFormatted(),
                    'time_start' => '09:00',
                    'time_end'   => '22:00',
                    'allday'     => true,
                ]);
            }
        }

        $this->info("Reminders sent: hourly={$hourly->count()}");
    }
}
