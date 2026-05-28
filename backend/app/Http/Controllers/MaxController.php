<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MaxBotService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MaxController extends Controller
{
    public function __construct(
        private MaxBotService       $max,
        private NotificationService $notify,
    ) {}

    /**
     * Webhook от Max — входящие обновления
     * POST /api/max/webhook
     */
    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();
        $type   = $update['update_type'] ?? null;

        Log::info('Max webhook FULL', $update);

        if ($type === 'message_created') {
            $this->handleMessage($update);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(array $update): void
    {
        $message  = $update['message'] ?? [];
        $sender   = $message['sender'] ?? [];
        $chatId   = (string) ($sender['user_id'] ?? null);
        $username = $sender['username'] ?? null;
        $name     = $sender['name'] ?? null;
        $text     = $message['body']['text'] ?? '';

        if (!$chatId) return;

        // Ищем пользователя по max_chat_id или username
        $user = User::where('max_chat_id', $chatId)->first()
            ?? ($username ? User::where('max_username', $username)->first() : null);

        if ($user) {
            $updates = ['max_chat_id' => $chatId];
            if ($username) $updates['max_username'] = $username;
            $user->update($updates);
        }

        if (str_starts_with($text, '/start')) {
            $this->sendStart($chatId, $name, $user);
        }
    }

    private function sendStart(string $chatId, ?string $name, ?User $user): void
    {
        $namePart = $name ? ", {$name}" : '';
        $text = "👋 Привет{$namePart}! Это бот <b>RoltHall</b> — танцевальный зал в Краснодаре.\n\n"
            . "Для бронирования перейдите на сайт:\n"
            . "<a href=\"https://hall.roltworld.com/calendar\">hall.roltworld.com/calendar</a>\n\n"
            . "После оплаты уведомления о бронях будут приходить сюда.\n\n"
            . "🪪 Ваш ID: <code>{$chatId}</code>";

        $this->max->sendMessage($chatId, $text);
    }

    /**
     * Регистрируем webhook в Max
     * GET /api/max/set-webhook
     */
    public function setWebhook(): JsonResponse
    {
        $url    = config('app.url') . '/api/max/webhook';
        $result = $this->max->setWebhook($url);

        return response()->json(['ok' => true, 'url' => $url, 'result' => $result]);
    }

    /**
     * Информация о webhook
     * GET /api/max/webhook-info
     */
    public function webhookInfo(): JsonResponse
    {
        return response()->json($this->max->getWebhookInfo());
    }

    /**
     * Тест — отправить сообщение администратору
     * GET /api/max/test
     */
    public function test(): JsonResponse
    {
        $adminChatId = config('services.max.admin_chat_id');
        if (!$adminChatId) {
            return response()->json(['ok' => false, 'error' => 'MAX_ADMIN_CHAT_ID не задан']);
        }

        $ok = $this->max->sendMessage($adminChatId, '🤖 <b>RoltHall Max Bot</b> подключён и готов к работе!');

        return response()->json(['ok' => $ok]);
    }

    /**
     * Информация о боте
     * GET /api/max/me
     */
    public function me(): JsonResponse
    {
        return response()->json($this->max->getMe());
    }
}
