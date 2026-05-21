<?php

namespace App\Http\Controllers;

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
        $data = $request->validate([
            'hall_id'        => 'required|integer|exists:halls,id',
            'date'           => 'required|date|after_or_equal:today',
            'time_start'     => 'required|regex:/^\d{2}:\d{2}$/',
            'time_end'       => 'required|regex:/^\d{2}:\d{2}$/',
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
            $booking = $this->bookings->createHold($data);
            $payData = $this->bookings->initPayment($booking);

            $result = $this->tbank->init([
                'amount'      => $payData['amount'] * 100, // T-Bank — копейки
                'order_id'    => $payData['order_id'],
                'description' => $payData['description'],
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

        $this->notify->sendRaw(
            "❌ <b>Отмена брони #{$booking->id}</b>\n" .
            "👤 {$booking->client->name}\n" .
            "📅 {$booking->getDateFormatted()} {$booking->getTimeRangeLabel()}\n" .
            ($refund ? "💸 Возврат предоплаты" : "⚠️ Без возврата (менее 6 ч)")
        );

        return response()->json(['ok' => true, 'refund' => $refund]);
    }
}
