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
        $from   = $message['from'] ?? [];
        $chatId = $from['id'] ?? ($message['chat']['id'] ?? null);
        if (!$chatId) return;

        $username  = $from['username']   ?? null;
        $firstName = $from['first_name'] ?? null;
        $text      = $message['text']    ?? '';

        // Пользователь поделился контактом
        if (isset($message['contact'])) {
            $this->handleContact($message['contact'], $chatId);
            return;
        }

        // Найти пользователя по chat_id или username
        $user = User::where('telegram_chat_id', (string) $chatId)->first()
            ?? ($username ? User::where('telegram_username', $username)->first() : null);

        if ($user) {
            $updates = ['telegram_chat_id' => (string) $chatId];
            if ($username) $updates['telegram_username'] = $username;
            $user->update($updates);

            // Подтягиваем аватар если ещё нет
            if (!$user->telegram_avatar_url) {
                $this->fetchAndSaveAvatar($user, $chatId);
            }
        }

        match (true) {
            str_starts_with($text, '/start')   => $this->sendStart($chatId, $firstName, $user),
            str_starts_with($text, '/booking') => $this->sendWebAppButton(
                $chatId,
                "📅 Забронировать зал\n\nВыберите удобный день и время, оплатите онлайн — бронь подтвердится автоматически.",
                '📅 Открыть расписание',
                config('app.url') . '/calendar',
            ),
            str_starts_with($text, '/lk') => $this->sendWebAppButton(
                $chatId,
                "👤 Личный кабинет\n\nПосмотрите ваши брони, статус оплаты и историю посещений.",
                '👤 Открыть личный кабинет',
                config('app.url') . '/profile',
            ),
            str_starts_with($text, '/mychat') => $this->sendMessage(
                $chatId,
                "Chat ID: <code>{$chatId}</code>\n" .
                "Thread ID: <code>" . ($message['message_thread_id'] ?? 'нет (личный чат)') . "</code>",
            ),
            default => null,
        };
    }

    /**
     * Пользователь поделился контактом — сохраняем телефон
     */
    private function handleContact(array $contact, int|string $chatId): void
    {
        $rawPhone = $contact['phone_number'] ?? null;
        if (!$rawPhone) return;

        // Нормализуем: убираем пробелы и дефисы, добавляем +
        $digits = preg_replace('/[^\d]/', '', $rawPhone);
        $phone  = '+' . $digits;

        $user = User::where('telegram_chat_id', (string) $chatId)->first()
            ?? User::where('phone', $phone)->first();

        if ($user) {
            $data = ['telegram_chat_id' => (string) $chatId];
            if (!$user->phone) $data['phone'] = $phone;
            $user->update($data);
        }

        // Убираем reply-клавиатуру и благодарим
        $this->telegram->sendMessage([
            'chat_id'      => $chatId,
            'text'         => "✅ Отлично! Теперь уведомления о бронях будут приходить сюда.",
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);
    }

    /**
     * Приветствие /start — inline-кнопка WebApp + reply-клавиатура для телефона
     */
    private function sendStart(int|string $chatId, ?string $firstName, ?User $user): void
    {
        $name = $firstName ? ", {$firstName}" : '';

        try {
            // Сообщение 1: приветствие + кнопка открыть Mini App
            $this->telegram->sendMessage([
                'chat_id'      => $chatId,
                'text'         => "👋 Привет{$name}! Это бот <b>RoltHall</b> — танцевальный зал в Краснодаре.\n\nНажми кнопку ниже чтобы выбрать время и забронировать зал. После оплаты уведомление придёт сюда.",
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text'    => '📅 Забронировать зал',
                            'web_app' => ['url' => config('app.url') . '/calendar'],
                        ],
                    ]],
                ]),
            ]);

            // Сообщение 2: запрос телефона (только если ещё не сохранён)
            $needPhone = !$user || !$user->phone;
            if ($needPhone) {
                $this->telegram->sendMessage([
                    'chat_id'      => $chatId,
                    'text'         => "📱 Поделитесь номером телефона — так мы свяжем уведомления с вашими бронями.",
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode([
                        'keyboard' => [[
                            ['text' => '📱 Поделиться номером', 'request_contact' => true],
                        ]],
                        'resize_keyboard'   => true,
                        'one_time_keyboard' => true,
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram sendStart error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Получаем аватар пользователя через API и сохраняем URL
     */
    private function fetchAndSaveAvatar(User $user, int|string $chatId): void
    {
        try {
            $photos = $this->telegram->getUserProfilePhotos([
                'user_id' => $chatId,
                'limit'   => 1,
            ]);

            $fileId = $photos['photos'][0][0]['file_id'] ?? null;
            if (!$fileId) return;

            $file     = $this->telegram->getFile(['file_id' => $fileId]);
            $filePath = $file['file_path'] ?? null;
            if (!$filePath) return;

            $token = config('services.telegram.bot_token');
            $url   = "https://api.telegram.org/file/bot{$token}/{$filePath}";

            $user->update(['telegram_avatar_url' => $url]);
        } catch (\Throwable $e) {
            Log::error('TG avatar fetch error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Сообщение с одной WebApp-кнопкой
     */
    private function sendWebAppButton(int|string $chatId, string $text, string $btnLabel, string $url): void
    {
        try {
            $this->telegram->sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => $btnLabel, 'web_app' => ['url' => $url]],
                    ]],
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram sendWebAppButton error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Отправляем произвольное сообщение в Telegram
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
