<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Hall;
use App\Models\PricingRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    /**
     * Создаём hold-бронирование (10 минут на оплату)
     */
    public function createHold(array $data): Booking
    {
        $hall      = Hall::findOrFail($data['hall_id']);
        $date      = $data['date'];
        $timeStart = $data['time_start'] . ':00';
        $timeEnd   = $data['time_end']   . ':00';
        $hours     = $this->calcHours($data['time_start'], $data['time_end']);

        // Проверяем конфликт слотов
        $conflict = Booking::where('hall_id', $hall->id)
            ->where('date', $date)
            ->whereNotIn('status', [Booking::STATUS_CANCELLED, Booking::STATUS_DRAFT])
            ->where('time_start', '<', $timeEnd)
            ->where('time_end',   '>', $timeStart)
            ->exists();

        if ($conflict) {
            throw new \RuntimeException('Выбранное время уже занято. Пожалуйста, выберите другое.');
        }

        // Рассчитываем стоимость
        $dayType      = $this->getDayType($date);
        $pricePerHour = $this->getPrice($hall->id, $dayType, $hours);
        $total        = $pricePerHour * $hours;
        $prepayment   = (int) round($total * 0.5);

        return DB::transaction(function () use ($data, $hall, $date, $timeStart, $timeEnd, $hours, $total, $prepayment) {
            $client = $this->resolveClient($data);

            return Booking::create([
                'client_id'         => $client->id,
                'hall_id'           => $hall->id,
                'date'              => $date,
                'time_start'        => $timeStart,
                'time_end'          => $timeEnd,
                'duration_hours'    => $hours,
                'format'            => 'single',
                'status'            => Booking::STATUS_HOLD,
                'total_amount'      => $total,
                'prepayment_amount' => $prepayment,
                'hold_expires_at'   => now()->addMinutes(10),
                'consent_offer'     => $data['consent_offer']  ?? false,
                'consent_policy'    => $data['consent_policy'] ?? false,
                'consent_refund'    => $data['consent_refund'] ?? false,
                'notes'             => $data['notes'] ?? null,
            ])->load(['hall', 'client']);
        });
    }

    /**
     * Переводим в pending_payment и возвращаем данные для T-Bank Init
     */
    public function initPayment(Booking $booking): array
    {
        if ($booking->status !== Booking::STATUS_HOLD) {
            throw new \RuntimeException('Бронирование недоступно для оплаты.');
        }

        if ($booking->isHoldExpired()) {
            $booking->update(['status' => Booking::STATUS_CANCELLED]);
            throw new \RuntimeException('Время резервирования истекло. Начните бронирование заново.');
        }

        $booking->update(['status' => Booking::STATUS_PENDING_PAYMENT]);

        return [
            'amount'      => $booking->prepayment_amount,
            'order_id'    => 'booking-' . $booking->id,
            'description' => "Предоплата {$booking->hall->name} · {$booking->getDateFormatted()} {$booking->getTimeRangeLabel()}",
            'email'       => $booking->client->email,
            'phone'       => $booking->client->phone,
        ];
    }

    /**
     * Подтверждаем оплату — вызывается из PaymentController::webhook
     */
    public function confirmPayment(Booking $booking, string $transactionId): void
    {
        $booking->update([
            'status'         => Booking::STATUS_PAID,
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Отмена брони. Возвращает: нужен ли возврат предоплаты.
     */
    public function cancel(Booking $booking): bool
    {
        $refund = $this->canRefund($booking);
        $booking->update(['status' => Booking::STATUS_CANCELLED]);
        return $refund;
    }

    public function canRefund(Booking $booking): bool
    {
        $start = Carbon::parse("{$booking->date->toDateString()} {$booking->time_start}");
        return now()->diffInHours($start, false) >= 6;
    }

    // ── Private ──────────────────────────────────────────────────────────

    private function resolveClient(array $data): Client
    {
        $user = User::where('phone', $data['phone'])->first();

        if (!$user) {
            $user = User::create([
                'name'              => $data['name'],
                'email'             => $data['email'] ?? ($data['phone'] . '@guest.rolthall.ru'),
                'phone'             => $data['phone'],
                'telegram_username' => $data['telegram'] ?? null,
                'password'          => bcrypt(Str::random(16)),
            ]);
        }

        return Client::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name'              => $data['name'],
                'phone'             => $data['phone'],
                'email'             => $data['email']    ?? null,
                'telegram_username' => $data['telegram'] ?? null,
            ]
        );
    }

    private function calcHours(string $start, string $end): int
    {
        return (int) explode(':', $end)[0] - (int) explode(':', $start)[0];
    }

    private function getDayType(string $date): string
    {
        return in_array(date('N', strtotime($date)), [6, 7]) ? 'weekend' : 'weekday';
    }

    private function getPrice(int $hallId, string $dayType, int $hours): int
    {
        $rule = PricingRule::where('hall_id', $hallId)
            ->where('day_type', $dayType)
            ->where('is_active', true)
            ->where('min_hours', '<=', $hours)
            ->where(fn($q) => $q->whereNull('max_hours')->orWhere('max_hours', '>=', $hours))
            ->orderBy('min_hours', 'desc')
            ->first();

        return $rule?->price_per_hour ?? 0;
    }
}
