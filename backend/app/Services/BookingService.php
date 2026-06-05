<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Hall;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * Создаём hold-бронирование (10 минут на оплату)
     */
    public function createHold(array $data): Booking
    {
        $hall       = Hall::findOrFail($data['hall_id']);
        $date       = $data['date'];
        $format     = $data['format']      ?? 'hourly';
        $guestCount = (int)($data['guest_count'] ?? 1);
        $dayType    = $this->pricing->getDayType($date);

        if ($format === 'allday') {
            $timeStart = '09:00:00';
            $timeEnd   = '22:00:00';
            $hours     = 13;
        } else {
            $timeStart = $data['time_start'] . ':00';
            $timeEnd   = $data['time_end']   . ':00';
            $hours     = $this->pricing->calcHours($data['time_start'], $data['time_end']);
        }

        // Проверяем конфликт слотов (hold с истёкшим временем не считается занятым)
        $conflict = Booking::where('hall_id', $hall->id)
            ->where('date', $date)
            ->whereNotIn('status', [Booking::STATUS_CANCELLED, Booking::STATUS_DRAFT])
            ->where(function ($q) {
                $q->where('status', '!=', Booking::STATUS_HOLD)
                  ->orWhere('hold_expires_at', '>', now());
            })
            ->where('time_start', '<', $timeEnd)
            ->where('time_end',   '>', $timeStart)
            ->exists();

        if ($conflict) {
            throw new \RuntimeException('Выбранное время уже занято. Пожалуйста, выберите другое.');
        }

        // Рассчитываем стоимость по новой матрице тарифов
        $rule       = $this->pricing->findRule($hall->id, $dayType, $format, $hours, $guestCount);
        $total      = $this->pricing->calcTotal($rule, $format, $hours);
        $prepayment = $this->pricing->calcPrepayment($total, $rule?->prepayment_percent ?? 100);

        return DB::transaction(function () use ($data, $hall, $date, $timeStart, $timeEnd, $hours, $guestCount, $format, $total, $prepayment) {
            $client = $this->resolveClient($data);

            return Booking::create([
                'client_id'         => $client->id,
                'hall_id'           => $hall->id,
                'date'              => $date,
                'time_start'        => $timeStart,
                'time_end'          => $timeEnd,
                'duration_hours'    => $hours,
                'guest_count'       => $guestCount,
                'format'            => $format,
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
     * Hold для нескольких почасовых слотов (разные дни) — одна оплата, N броней.
     * slots: [{date, time_start, time_end, paid_amount?}]
     * Цена каждого слота считается через PricingService.
     * Возвращает ['bookings' => Booking[], 'group_id' => string, 'total' => int]
     */
    public function createMultiHold(array $data): array
    {
        $hall       = Hall::findOrFail($data['hall_id']);
        $slots      = $data['slots'];
        $groupId    = (string) Str::uuid();
        $guestCount = (int)($data['guest_count'] ?? 1);

        $bookings = DB::transaction(function () use ($data, $hall, $slots, $groupId, $guestCount) {
            $client   = $this->resolveClient($data);
            $bookings = [];

            foreach ($slots as $slot) {
                $timeStart = $slot['time_start'] . ':00';
                $timeEnd   = $slot['time_end']   . ':00';
                $hours     = $this->pricing->calcHours($slot['time_start'], $slot['time_end']);
                $dayType   = $this->pricing->getDayType($slot['date']);

                $conflict = Booking::where('hall_id', $hall->id)
                    ->where('date', $slot['date'])
                    ->whereNotIn('status', [Booking::STATUS_CANCELLED, Booking::STATUS_DRAFT])
                    ->where(function ($q) {
                        $q->where('status', '!=', Booking::STATUS_HOLD)
                          ->orWhere('hold_expires_at', '>', now());
                    })
                    ->where('time_start', '<', $timeEnd)
                    ->where('time_end',   '>', $timeStart)
                    ->exists();

                if ($conflict) {
                    throw new \RuntimeException("Слот {$slot['date']} {$slot['time_start']}–{$slot['time_end']} уже занят.");
                }

                $rule       = $this->pricing->findRule($hall->id, $dayType, 'hourly', $hours, $guestCount);
                $totalSlot  = $this->pricing->calcTotal($rule, 'hourly', $hours);
                $prepaySlot = isset($slot['paid_amount'])
                    ? (int)$slot['paid_amount']
                    : $this->pricing->calcPrepayment($totalSlot, $rule?->prepayment_percent ?? 100);

                $bookings[] = Booking::create([
                    'client_id'         => $client->id,
                    'hall_id'           => $hall->id,
                    'group_id'          => $groupId,
                    'date'              => $slot['date'],
                    'time_start'        => $timeStart,
                    'time_end'          => $timeEnd,
                    'duration_hours'    => $hours,
                    'format'            => 'hourly',
                    'status'            => Booking::STATUS_HOLD,
                    'total_amount'      => $totalSlot,
                    'prepayment_amount' => $prepaySlot,
                    'hold_expires_at'   => now()->addMinutes(10),
                    'consent_offer'     => $data['consent_offer']  ?? true,
                    'consent_policy'    => $data['consent_policy'] ?? true,
                    'consent_refund'    => $data['consent_refund'] ?? true,
                    'notes'             => $data['notes'] ?? null,
                ]);
            }

            return $bookings;
        });

        $total = (int) array_sum(array_map(fn($b) => $b->total_amount, $bookings));
        return ['bookings' => $bookings, 'group_id' => $groupId, 'total' => $total];
    }

    /**
     * Hold для мероприятия — цена берётся из pkg_price (не из PricingRule)
     */
    public function createEventHold(array $data): Booking
    {
        $hall       = Hall::findOrFail($data['hall_id']);
        $dates      = $data['dates'];
        $pkgPrice   = $data['pkg_price'];                    // ₽ за день
        $total      = $pkgPrice * count($dates);
        $prepayment = (int) round($total * 0.5);

        return DB::transaction(function () use ($data, $hall, $dates, $total, $prepayment) {
            $client = $this->resolveClient($data);

            return Booking::create([
                'client_id'         => $client->id,
                'hall_id'           => $hall->id,
                'date'              => $dates[0],
                'time_start'        => '09:00:00',
                'time_end'          => '22:00:00',
                'duration_hours'    => 13,
                'format'            => 'event',
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
        return $this->pricing->canRefund($start);
    }

    // ── Private ──────────────────────────────────────────────────────────

    public function resolveClient(array $data): Client
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

        // Сохраняем telegram_chat_id если пришёл из TG WebApp
        if (!empty($data['tg_user_id']) && empty($user->telegram_chat_id)) {
            $user->update(['telegram_chat_id' => (string) $data['tg_user_id']]);
        }

        // Сохраняем max_chat_id если пришёл из Max
        if (!empty($data['max_user_id']) && empty($user->max_chat_id)) {
            $user->update(['max_chat_id' => (string) $data['max_user_id']]);
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

}
