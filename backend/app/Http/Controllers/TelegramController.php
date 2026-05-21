<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

class TelegramController extends Controller
{
    private TelegramApi $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramApi(config('services.telegram.bot_token'));
    }

    /**
     * Принимаем входящие обновления от Telegram (webhook endpoint)
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('Telegram update', $request->all());

        $update = $request->all();

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Обрабатываем входящее сообщение
     */
    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $text   = $message['text'] ?? '';

        if (!$chatId) return;

        match (true) {
            str_starts_with($text, '/start') => $this->sendMessage(
                $chatId,
                "👋 Привет! Это бот <b>RoltHall</b>.\n\nДля бронирования залов перейдите на сайт:\nhttps://hall.roltworld.com"
            ),
            str_starts_with($text, '/mychat') => $this->sendMessage(
                $chatId,
                "Ваш Chat ID: <code>{$chatId}</code>"
            ),
            default => null,
        };
    }

    /**
     * Отправляем сообщение в Telegram
     */
    public function sendMessage(int|string $chatId, string $text): void
    {
        try {
            $this->telegram->sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram send error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Регистрируем webhook у Telegram (вызывается один раз вручную)
     * GET /api/telegram/set-webhook
     */
    public function setWebhook(): JsonResponse
    {
        $url    = config('app.url') . '/api/telegram/webhook';
        $result = $this->telegram->setWebhook(['url' => $url]);

        return response()->json([
            'url'    => $url,
            'result' => $result,
        ]);
    }

    /**
     * Получаем информацию о webhook
     * GET /api/telegram/webhook-info
     */
    public function webhookInfo(): JsonResponse
    {
        $info = $this->telegram->getWebhookInfo();
        return response()->json($info);
    }
}
