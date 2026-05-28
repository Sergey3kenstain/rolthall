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

        Log::warning('Max webhook', ['type' => $type, 'user' => $update['message']['sender']['user_id'] ?? null]);

        if ($type === 'message_created') {
            $this->handleMessage($update);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(array $update): void
    {
        $message  = $update['message'] ?? [];
        $sender   = $message['sender'] ?? [];

        // user_id используется как адрес для отправки через ?user_id=
        $userId   = (string) ($sender['user_id'] ?? null);
        $username = $sender['username'] ?? null;
        $name     = $sender['name'] ?? $sender['first_name'] ?? null;
        $text     = $message['body']['text'] ?? '';

        if (!$userId) return;

        $user = User::where('max_chat_id', $userId)->first()
            ?? ($username ? User::where('max_username', $username)->first() : null);

        if ($user) {
            $upd = ['max_chat_id' => $userId];
            if ($username) $upd['max_username'] = $username;
            $user->update($upd);
        }

        if (str_starts_with($text, '/start')) {
            $this->sendStart($userId, $name, $user);
        } elseif (str_starts_with($text, '/booking')) {
            $this->sendBooking($userId);
        } elseif (str_starts_with($text, '/lk')) {
            $this->sendLk($userId, $name);
        }
    }

    private function sendStart(string $chatId, ?string $name, ?User $user): void
    {
        $namePart = $name ? ", {$name}" : '';
        $text = "👋 Привет{$namePart}! Это бот <b>RoltHall</b> — танцевальный зал в Краснодаре.\n\n"
            . "После оплаты уведомления о бронях будут приходить сюда.\n\n"
            . "🪪 Ваш ID: <code>{$chatId}</code>\n\n"
            . "<i>Для вызова меню напишите /lk</i>";

        $this->max->sendMessage($chatId, $text, [[
            ['type' => 'link', 'text' => '📅 Забронировать зал',  'url' => "https://hall.roltworld.com/calendar?via=max&max_id={$chatId}"],
            ['type' => 'link', 'text' => '👤 Личный кабинет',     'url' => "https://hall.roltworld.com/profile?via=max"],
        ]]);
    }

    private function sendBooking(string $chatId): void
    {
        $text = "📅 <b>Бронирование зала RoltHall</b>\n\nВыберите дату и время:";

        $this->max->sendMessage($chatId, $text, [[
            ['type' => 'link', 'text' => '📅 Открыть календарь', 'url' => "https://hall.roltworld.com/calendar?via=max&max_id={$chatId}"],
        ]]);
    }

    private function sendLk(string $chatId, ?string $name): void
    {
        $namePart = $name ? ", {$name}" : '';
        $text = "👤 Привет{$namePart}!\n\nВаш личный кабинет — история броней и данные профиля.";

        $this->max->sendMessage($chatId, $text, [
            [
                ['type' => 'link', 'text' => '🚪 Войти в личный кабинет', 'url' => 'https://hall.roltworld.com/profile?via=max'],
            ],
            [
                ['type' => 'link', 'text' => '📅 Забронировать зал', 'url' => "https://hall.roltworld.com/calendar?via=max&max_id={$chatId}"],
            ],
        ]);
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
