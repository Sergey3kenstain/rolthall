<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\TBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private BookingService     $bookings,
        private TBankService       $tbank,
        private NotificationService $notify,
    ) {}

    /**
     * Создать hold и вернуть payment_url
     * POST /api/bookings/hold
     */
    public function hold(Request $request): JsonResponse
    {
        if ($request->input('_hp') !== null && $request->input('_hp') !== '') {
            return response()->json(['ok' => false, 'error' => 'Bad request'], 422);
        }

        $data = $request->validate([
            'hall_id'        => 'required|integer|exists:halls,id',
            'format'         => 'nullable|in:hourly,event,allday',
            'guest_count'    => 'nullable|integer|min:1|max:500',
            'date'           => 'required|date|after_or_equal:today',
            'time_start'     => 'nullable|regex:/^\d{2}:\d{2}$/',
            'time_end'       => 'nullable|regex:/^\d{2}:\d{2}$/',
            'name'           => 'required|string|max:191',
            'phone'          => 'required|string|max:30',
            'email'          => 'nullable|email|max:191',
            'telegram'       => 'nullable|string|max:100',
            'tg_user_id'     => 'nullable|string|max:50',
            'notes'          => 'nullable|string|max:1000',
            'consent_offer'  => 'boolean',
            'consent_policy' => 'boolean',
            'consent_refund' => 'boolean',
        ]);

        try {
            $booking = $this->bookings->createHold($data);
            $payData = $this->bookings->initPayment($booking);

            $result = $this->tbank->init([
                'amount'      => $payData['amount'] * 100, // T-Bank — копейки
                'order_id'    => $payData['order_id'],
                'description' => $payData['description'],
                'email'       => $payData['email'] ?? null,
                'phone'       => $payData['phone'] ?? null,
            ]);

            if (!$result['ok']) {
                $booking->update(['status' => Booking::STATUS_CANCELLED]);
                return response()->json(['ok' => false, 'error' => $result['error']], 422);
            }

            ActionLog::write('booking.create', null, 'client', Booking::class, $booking->id, [
                'name'  => $data['name'],
                'date'  => $data['date'],
                'hall'  => $data['hall_id'],
                'total' => $booking->total_amount,
            ], $request);

            if (!empty($data['tg_user_id'])) {
                $tgUser = \App\Models\User::where('telegram_chat_id', $data['tg_user_id'])->first();
                if ($tgUser) $this->notify->refreshAvatar($tgUser);
            }

            return response()->json([
                'ok'          => true,
                'booking_id'  => $booking->id,
                'payment_url' => $result['PaymentURL'],
                'expires_at'  => $booking->hold_expires_at->toISOString(),
                'total'       => $booking->total_amount,
                'prepayment'  => $booking->prepayment_amount,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Hold для мероприятия — принимает пакет и список дат
     * POST /api/bookings/event-hold
     */
    public function eventHold(Request $request): JsonResponse
    {
        if ($request->input('_hp') !== null && $request->input('_hp') !== '') {
            return response()->json(['ok' => false, 'error' => 'Bad request'], 422);
        }

        $data = $request->validate([
            'hall_id'        => 'required|integer|exists:halls,id',
            'dates'          => 'required|array|min:1',
            'dates.*'        => 'required|date|after_or_equal:today',
            'pkg_price'      => 'required|integer|min:1',   // цена пакета за день, в рублях
            'name'           => 'required|string|max:191',
            'phone'          => 'required|string|max:30',
            'email'          => 'nullable|email|max:191',
            'telegram'       => 'nullable|string|max:100',
            'notes'          => 'nullable|string|max:1000',
            'consent_offer'  => 'boolean',
            'consent_policy' => 'boolean',
            'consent_refund' => 'boolean',
        ]);

        try {
            $booking = $this->bookings->createEventHold($data);
            $payData = $this->bookings->initPayment($booking);

            $result = $this->tbank->init([
                'amount'      => $payData['amount'] * 100,
                'order_id'    => $payData['order_id'],
                'description' => $payData['description'],
                'email'       => $payData['email'] ?? null,
                'phone'       => $payData['phone'] ?? null,
            ]);

            if (!$result['ok']) {
                $booking->update(['status' => Booking::STATUS_CANCELLED]);
                return response()->json(['ok' => false, 'error' => $result['error']], 422);
            }

            return response()->json([
                'ok'          => true,
                'booking_id'  => $booking->id,
                'payment_url' => $result['PaymentURL'],
                'expires_at'  => $booking->hold_expires_at->toISOString(),
                'total'       => $booking->total_amount,
                'prepayment'  => $booking->prepayment_amount,
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Статус брони
     * GET /api/bookings/{id}/status
     */
    public function status(Booking $booking): JsonResponse
    {
        return response()->json([
            'id'         => $booking->id,
            'status'     => $booking->status,
            'expires_at' => $booking->hold_expires_at?->toISOString(),
        ]);
    }

    /**
     * Отмена брони клиентом
     * POST /api/bookings/{id}/cancel
     */
    public function cancel(Booking $booking, Request $request): JsonResponse
    {
        if (!in_array($booking->status, [Booking::STATUS_PAID, Booking::STATUS_CONFIRMED])) {
            return response()->json(['ok' => false, 'error' => 'Бронь нельзя отменить в текущем статусе'], 422);
        }

        $refund = $this->bookings->cancel($booking);

        ActionLog::write('booking.cancel', $booking->client->user_id ?? null, 'client',
            Booking::class, $booking->id, ['refund' => $refund], $request);

        $this->notify->sendRaw(
            "❌ <b>Отмена брони #{$booking->id}</b>\n" .
            "👤 {$booking->client->name}\n" .
            "📅 {$booking->getDateFormatted()} {$booking->getTimeRangeLabel()}\n" .
            ($refund ? "💸 Возврат предоплаты" : "⚠️ Без возврата (менее 6 ч)")
        );

        return response()->json(['ok' => true, 'refund' => $refund]);
    }
}
