<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TBankService
{
    private string $terminalKey;
    private string $secretKey;
    private string $apiUrl;

    public function __construct()
    {
        $this->terminalKey = config('services.tbank.terminal_key');
        $this->secretKey   = config('services.tbank.secret_key');
        $this->apiUrl      = config('services.tbank.api_url');
    }

    /**
     * Инициализируем платёж — получаем ссылку для оплаты
     *
     * @return array{ok: bool, payment_id?: string, payment_url?: string, error?: string}
     */
    public function init(array $params): array
    {
        $data = array_merge([
            'TerminalKey' => $this->terminalKey,
            'Amount'      => $params['amount'],        // в копейках
            'OrderId'     => $params['order_id'],      // уникальный ID брони
            'Description' => $params['description'],
            'NotificationURL' => config('app.url') . '/api/payment/webhook',
            'SuccessURL'  => config('app.url') . '/booking/success',
            'FailURL'     => config('app.url') . '/booking/fail',
        ], $params['extra'] ?? []);

        // Фискальный чек (54-ФЗ) — обязателен для T-Bank
        $receipt = [
            'Taxation' => config('services.tbank.taxation', 'usn_income'),
            'Items'    => [[
                'Name'          => mb_substr($params['description'], 0, 128),
                'Price'         => $params['amount'],
                'Quantity'      => 1.00,
                'Amount'        => $params['amount'],
                'Tax'           => config('services.tbank.vat', 'none'),
                'PaymentMethod' => 'full_prepayment',
                'PaymentObject' => 'service',
            ]],
        ];
        if (!empty($params['email'])) {
            $receipt['Email'] = $params['email'];
        } elseif (!empty($params['phone'])) {
            $receipt['Phone'] = $params['phone'];
        } else {
            $receipt['Email'] = 'noreply@rolthall.ru'; // fallback — не должен достигаться
        }
        $data['Receipt'] = $receipt;

        $data['Token'] = $this->generateToken($data);

        return $this->request('Init', $data);
    }

    /**
     * Проверяем статус платежа
     */
    public function getState(string $paymentId): array
    {
        $data = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId'   => $paymentId,
        ];
        $data['Token'] = $this->generateToken($data);

        return $this->request('GetState', $data);
    }

    /**
     * Отмена / возврат платежа
     */
    public function cancel(string $paymentId, ?int $amount = null): array
    {
        $data = [
            'TerminalKey' => $this->terminalKey,
            'PaymentId'   => $paymentId,
        ];

        if ($amount !== null) {
            $data['Amount'] = $amount; // частичный возврат в копейках
        }

        $data['Token'] = $this->generateToken($data);

        return $this->request('Cancel', $data);
    }

    /**
     * Обрабатываем webhook от T-Bank (после оплаты)
     * Возвращает true если подпись валидна
     */
    public function verifyWebhook(array $payload): bool
    {
        $receivedToken = $payload['Token'] ?? '';
        unset($payload['Token']);

        $expectedToken = $this->generateToken($payload);

        return hash_equals($expectedToken, $receivedToken);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function request(string $method, array $data): array
    {
        try {
            $httpResponse = Http::timeout(15)
                ->post($this->apiUrl . $method, $data);

            $response = $httpResponse->json() ?? [];

            if (empty($response)) {
                $body = $httpResponse->body();
                Log::warning("TBank {$method} empty response", ['status' => $httpResponse->status(), 'body' => $body]);
                return ['ok' => false, 'error' => "Empty response (HTTP {$httpResponse->status()}): {$body}"];
            }

            if (empty($response['Success'])) {
                Log::warning("TBank {$method} failed", $response);
                return [
                    'ok'    => false,
                    'error' => $response['Message'] ?? ($response['Details'] ?? 'Unknown error'),
                    'raw'   => $response,
                ];
            }

            return array_merge(['ok' => true], $response);
        } catch (\Throwable $e) {
            Log::error("TBank {$method} exception", ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Генерируем токен подписи T-Bank:
     * сортируем все поля по ключу, добавляем секрет, SHA256
     */
    private function generateToken(array $data): string
    {
        // Убираем массивы и null
        $filtered = array_filter($data, fn($v) => !is_array($v) && $v !== null);
        $filtered['Password'] = $this->secretKey;

        ksort($filtered);

        // Конвертируем каждое значение в строку по правилам T-Bank:
        // bool true → "true", bool false → "false", остальное — обычная строка
        $values = array_map(function ($v) {
            if ($v === true)  return 'true';
            if ($v === false) return 'false';
            return (string) $v;
        }, array_values($filtered));

        return hash('sha256', implode('', $values));
    }
}
