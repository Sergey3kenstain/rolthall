<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use App\Services\TBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private TBankService $tbank,
        private NotificationService $notify,
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
        Log::info('TBank webhook', $payload);

        // Проверяем подпись
        if (!$this->tbank->verifyWebhook($payload)) {
            Log::warning('TBank webhook: invalid token', $payload);
            return response('FAIL', 400);
        }

        $status    = $payload['Status']    ?? '';
        $orderId   = $payload['OrderId']   ?? '';
        $paymentId = $payload['PaymentId'] ?? '';
        $amount    = ($payload['Amount']   ?? 0) / 100; // копейки → рубли

        // Успешная оплата
        if ($status === 'CONFIRMED') {
            Log::info("Payment confirmed: {$orderId}, {$paymentId}, {$amount}₽");

            // Уведомляем администратора
            $this->notify->sendRaw(
                "💰 <b>Оплата получена</b>\n\n"
                . "📋 Заказ: <code>{$orderId}</code>\n"
                . "🔑 ID транзакции: <code>{$paymentId}</code>\n"
                . "💵 Сумма: {$amount} ₽"
            );
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
            'amount'      => 100 * 100,  // 100 рублей в копейках
            'order_id'    => 'test-' . time(),
            'description' => 'Тестовый платёж RoltHall',
        ]);

        return response()->json($result);
    }
}
