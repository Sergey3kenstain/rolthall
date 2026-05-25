<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\TBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private TBankService       $tbank,
        private NotificationService $notify,
        private BookingService     $bookings,
    ) {}

    /**
     * Инициализируем платёж — клиент получает ссылку для оплаты
     * POST /api/payment/init
     */
    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id'  => 'required|integer',
            'amount'      => 'required|integer|min:1',    // в рублях
            'description' => 'required|string|max:250',
        ]);

        $result = $this->tbank->init([
            'amount'      => $data['amount'] * 100,       // T-Bank принимает копейки
            'order_id'    => 'booking-' . $data['booking_id'],
            'description' => $data['description'],
        ]);

        if (!$result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        return response()->json([
            'ok'          => true,
            'payment_id'  => $result['PaymentId'],
            'payment_url' => $result['PaymentURL'],
        ]);
    }

    /**
     * Webhook от T-Bank — вызывается после оплаты
     * POST /api/payment/webhook
     */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        $payload = $request->all();
        Log::info('TBank webhook', ['status' => $payload['Status'] ?? '?', 'order' => $payload['OrderId'] ?? '?']);

        if (!$this->tbank->verifyWebhook($payload)) {
            Log::warning('TBank webhook: invalid token');
            return response('FAIL', 400);
        }

        $status    = $payload['Status']    ?? '';
        $orderId   = $payload['OrderId']   ?? '';
        $paymentId = $payload['PaymentId'] ?? '';
        $amount    = ($payload['Amount']   ?? 0) / 100;

        if ($status === 'CONFIRMED') {
            Log::info("TBank CONFIRMED: {$orderId}");

            // Находим бронь по order_id формата "booking-{id}"
            $bookingId = str_replace('booking-', '', $orderId);
            $booking   = Booking::with(['hall', 'client'])->find($bookingId);

            if ($booking) {
                $this->bookings->confirmPayment($booking, $paymentId);

                ActionLog::write('booking.paid', $booking->client->user_id ?? null, 'client',
                    Booking::class, $booking->id, [
                        'amount'         => $amount,
                        'transaction_id' => $paymentId,
                        'client'         => $booking->client->name ?? '—',
                    ]);

                // Уведомление администратору
                $this->notify->notifyAdminNewBooking([
                    'format'         => $booking->format,
                    'client_name'    => $booking->client->name,
                    'date'           => $booking->getDateFormatted(),
                    'time_start'     => substr($booking->time_start, 0, 5),
                    'time_end'       => substr($booking->time_end, 0, 5),
                    'hall_name'      => $booking->hall->name,
                    'phone'          => $booking->client->phone,
                    'email'          => $booking->client->email ?? '—',
                    'telegram'       => $booking->client->telegram_username ?? '—',
                    'prepayment'     => $booking->prepayment_amount,
                    'transaction_id' => $paymentId,
                ]);

                // Уведомление клиенту в личку (если он написал боту /start)
                $clientChatId = $booking->client->user->telegram_chat_id ?? null;
                if ($clientChatId) {
                    $this->notify->notifyClientConfirmed($clientChatId, [
                        'hall_name'  => $booking->hall->name,
                        'date'       => $booking->getDateFormatted(),
                        'time_start' => substr($booking->time_start, 0, 5),
                        'time_end'   => substr($booking->time_end, 0, 5),
                        'prepayment' => $booking->prepayment_amount,
                    ]);
                }
            } else {
                // Тестовый платёж без реальной брони
                $this->notify->sendRaw(
                    "💰 <b>Оплата получена</b>\n"
                    . "📋 Заказ: <code>{$orderId}</code>\n"
                    . "🔑 <code>{$paymentId}</code>\n"
                    . "💵 {$amount} ₽"
                );
            }
        }

        return response('OK', 200);
    }

    /**
     * Проверяем статус платежа
     * GET /api/payment/status/{paymentId}
     */
    public function status(string $paymentId): JsonResponse
    {
        $result = $this->tbank->getState($paymentId);
        return response()->json($result);
    }

    /**
     * Тестовый платёж — открыть в браузере для проверки
     * GET /api/payment/test
     */
    public function test(): JsonResponse
    {
        $result = $this->tbank->init([
            'amount'      => 50 * 100,  // 50 рублей
            'order_id'    => 'test-' . time(),
            'description' => 'Тестовый платёж RoltHall 50₽',
            'email'       => 'test@rolthall.ru',
        ]);

        return response()->json($result);
    }

    /**
     * Debug — показываем что отправляем в T-Bank (без реального запроса)
     * GET /api/payment/debug
     */
    public function debug(): JsonResponse
    {
        return response()->json([
            'terminal_key' => config('services.tbank.terminal_key'),
            'api_url'      => config('services.tbank.api_url'),
            'test_mode'    => config('services.tbank.test_mode'),
            'secret_set'   => !empty(config('services.tbank.secret_key')),
        ]);
    }
}
