<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
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

        // Сохраняем chat_id по username если пользователь известен
        $username = $message['chat']['username'] ?? null;
        if ($username) {
            User::where('telegram_username', $username)
                ->whereNull('telegram_chat_id')
                ->update(['telegram_chat_id' => (string) $chatId]);
        }

        match (true) {
            str_starts_with($text, '/start') => $this->sendMessage(
                $chatId,
                "👋 Привет! Это бот <b>RoltHall</b>.\n\nТеперь вы будете получать уведомления о своих бронях здесь.\n\nДля бронирования: https://hall.roltworld.com"
            ),
            str_starts_with($text, '/mychat') => $this->sendMessage(
                $chatId,
                "Chat ID: <code>{$chatId}</code>\n" .
                "Thread ID: <code>" . ($message['message_thread_id'] ?? 'нет (личный чат)') . "</code>",
                $message['message_thread_id'] ?? null
            ),
            default => null,
        };
    }

    /**
     * Отправляем сообщение в Telegram
     */
    public function sendMessage(int|string $chatId, string $text, ?int $threadId = null): void
    {
        try {
            $params = [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ];

            if ($threadId) {
                $params['message_thread_id'] = $threadId;
            }

            $this->telegram->sendMessage($params);
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
        $url = config('app.url') . '/api/telegram/webhook';

        try {
            $result = $this->telegram->setWebhook(['url' => $url]);
            return response()->json(['ok' => true, 'url' => $url, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
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

    /**
     * Тест — отправляем сообщение администратору
     * GET /api/telegram/test
     */
    public function test(): JsonResponse
    {
        try {
            (new NotificationService())->sendTestMessage();
            return response()->json(['ok' => true, 'message' => 'Тестовое сообщение отправлено']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
