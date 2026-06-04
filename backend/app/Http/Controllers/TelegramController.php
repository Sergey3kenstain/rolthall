<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

class TelegramController extends Controller
{
    private TelegramApi        $telegram;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->telegram      = new TelegramApi(config('services.telegram.bot_token'));
        $this->notifications = new NotificationService();
    }

    /**
     * Принимаем входящие обновления от Telegram (webhook endpoint)
     */
    public function webhook(Request $request): JsonResponse
    {
        $this->processUpdate($request->all());
        return response()->json(['ok' => true]);
    }

    /**
     * Обрабатываем одно обновление — вызывается из webhook и из polling-команды
     */
    public function processUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
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

            $this->notifications->refreshAvatar($user);
        }

        // Ключевые слова событий
        $trimmed = trim($text);
        if ($trimmed && !str_starts_with($trimmed, '/')) {
            $events = Event::where('status', 'active')->get();
            foreach ($events as $event) {
                $kw = trim($event->messenger_settings['tg']['bot_keyword'] ?? '');
                if ($kw && mb_strtolower($trimmed) === mb_strtolower($kw)) {
                    $this->sendEventWelcomeTg($chatId, $event);
                    return;
                }
            }
        }

        match (true) {
            str_starts_with($text, '/start')   => $this->sendStart($chatId, $firstName, $user),
            str_starts_with($text, '/booking') => $this->sendBooking($chatId),
            str_starts_with($text, '/lk')      => $this->sendLk($chatId),
            str_starts_with($text, '/mychat')  => $this->sendMessage(
                $chatId,
                "Chat ID: <code>{$chatId}</code>\n" .
                "Thread ID: <code>" . ($message['message_thread_id'] ?? 'нет (личный чат)') . "</code>",
            ),
            default => null,
        };
    }

    private function sendEventWelcomeTg(int|string $chatId, Event $event): void
    {
        $ms      = $event->messenger_settings ?? [];
        $tg      = $ms['tg'] ?? [];
        $default = "🎉 <b>{event}</b>\n\n📅 {date}\n\nЗарегистрируйтесь, чтобы забронировать место!";
        $text    = $tg['bot_welcome'] ?? $default;
        $btnText = $tg['bot_button']  ?? 'Зарегистрироваться →';

        $dateStr = $event->event_date?->format('d.m.Y') ?? '';
        $text    = str_replace(['{event}', '{date}'], [$event->title, $dateStr], $text);

        $url = config('app.url') . '/event/' . $event->slug;
        try {
            $this->telegram->sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => $btnText, 'web_app' => ['url' => $url]],
                ]]]),
            ]);
        } catch (\Throwable $e) {
            Log::error('TG sendEventWelcome', ['error' => $e->getMessage()]);
        }
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
        $cmds      = $this->cmdSettings();
        $namePart  = $firstName ? ", {$firstName}" : '';
        $rawText   = $cmds['start_text'] ?? "👋 Привет{name}! Это бот <b>RoltHall</b> — танцевальный зал в Краснодаре.\n\nНажми кнопку ниже чтобы выбрать время и забронировать зал. После оплаты уведомление придёт сюда.";
        $startText = str_replace('{name}', $namePart, $rawText);
        $startBtn  = $cmds['start_btn'] ?? '📅 Забронировать зал';

        try {
            // Сообщение 1: приветствие + кнопка открыть Mini App
            $this->telegram->sendMessage([
                'chat_id'      => $chatId,
                'text'         => $startText,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text'    => $startBtn,
                            'web_app' => ['url' => config('app.url') . '/calendar'],
                        ],
                    ]],
                ]),
            ]);

        } catch (\Throwable $e) {
            Log::error('Telegram sendStart error', ['error' => $e->getMessage()]);
        }
    }

    private function sendBooking(int|string $chatId): void
    {
        $cmds = $this->cmdSettings();
        $this->sendWebAppButton(
            $chatId,
            $cmds['booking_text'] ?? "📅 Забронировать зал\n\nВыберите удобный день и время, оплатите онлайн — бронь подтвердится автоматически.",
            $cmds['booking_btn']  ?? '📅 Открыть расписание',
            config('app.url') . '/calendar',
        );
    }

    private function sendLk(int|string $chatId): void
    {
        $cmds = $this->cmdSettings();
        $this->sendWebAppButton(
            $chatId,
            $cmds['lk_text'] ?? "👤 Личный кабинет\n\nПосмотрите ваши брони, статус оплаты и историю посещений.",
            $cmds['lk_btn']  ?? '👤 Открыть личный кабинет',
            config('app.url') . '/profile',
        );
    }

    private function cmdSettings(): array
    {
        $file = storage_path('app/telegram_settings.json');
        $s    = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        return $s['cmd_templates'] ?? [];
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
