<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

class NotificationService
{
    private TelegramApi $telegram;
    private string|int  $adminChatId;
    private ?int        $adminThreadId;

    public function __construct()
    {
        $this->telegram      = new TelegramApi(config('services.telegram.bot_token'));
        $this->adminChatId   = config('services.telegram.admin_chat_id');
        $this->adminThreadId = config('services.telegram.admin_thread_id') ?: null;
    }

    /**
     * Уведомление администратору о новой брони
     */
    public function notifyAdminNewBooking(array $data): void
    {
        $formatLabel = ($data['format'] ?? 'hourly') === 'event' ? 'Мероприятие' : 'Почасовая оплата';

        $guestLine = !empty($data['guest_count']) && $data['guest_count'] > 0
            ? "👥 <b>Гостей:</b> {$data['guest_count']} чел.\n"
            : '';

        $text = "🏠 <b>Новая бронь — RoltHall</b>\n\n"
            . "👤 <b>Клиент:</b> {$data['client_name']}\n"
            . "📞 <b>Телефон:</b> <a href=\"tel:{$data['phone']}\">{$data['phone']}</a>\n"
            . "✉️ <b>Email:</b> {$data['email']}\n"
            . "💬 <b>Телеграм:</b> @{$data['telegram']}\n\n"
            . "📋 <b>Формат:</b> {$formatLabel}\n"
            . "📅 <b>Дата:</b> {$data['date']}\n"
            . "🕐 <b>Время:</b> {$data['time_start']}–{$data['time_end']}\n"
            . "🏛 <b>Зал:</b> {$data['hall_name']}\n"
            . $guestLine . "\n"
            . "💰 <b>Предоплата:</b> {$data['prepayment']} ₽\n"
            . "🔑 <b>Транзакция:</b> <code>{$data['transaction_id']}</code>";

        $this->sendToAdmin($text);
    }

    /**
     * Уведомление клиенту о подтверждении брони
     */
    public function notifyClientConfirmed(int|string $chatId, array $data): void
    {
        $guestsLine = !empty($data['guest_count']) && $data['guest_count'] > 0
            ? "👥 <b>Гостей:</b> {$data['guest_count']} чел.\n"
            : '';

        $text = "✅ <b>Бронь подтверждена!</b>\n\n"
            . "🏛 <b>Зал:</b> {$data['hall_name']}\n"
            . "📅 <b>Дата:</b> {$data['date']}\n"
            . "🕐 <b>Время:</b> {$data['time_start']}–{$data['time_end']}\n"
            . $guestsLine
            . "💰 <b>Предоплата:</b> {$data['prepayment']} ₽\n\n"
            . "Ждём вас! По вопросам — свяжитесь с нами.";

        $this->send($chatId, $text);
    }

    /**
     * Напоминание клиенту за 24 часа
     */
    public function notifyClientReminder24h(int|string $chatId, array $data): void
    {
        $text = "⏰ <b>Напоминание</b>\n\n"
            . "Завтра у вас бронь в RoltHall!\n"
            . "🏛 {$data['hall_name']} · {$data['date']}, {$data['time_start']}–{$data['time_end']}\n\n"
            . "Отмена возможна не позднее чем за 6 часов до начала.";

        $this->send($chatId, $text);
    }

    /**
     * Напоминание за 2 часа
     */
    public function notifyClientReminder2h(int|string $chatId, array $data): void
    {
        $text = "⏰ Через 2 часа ваша бронь!\n"
            . "🏛 {$data['hall_name']} · {$data['time_start']}–{$data['time_end']}";

        $this->send($chatId, $text);
    }

    /**
     * Уведомление об отмене
     */
    public function notifyClientCancelled(int|string $chatId, bool $refunded): void
    {
        $text = "❌ <b>Бронь отменена</b>\n\n"
            . ($refunded
                ? "💸 Предоплата будет возвращена в течение 3–5 рабочих дней."
                : "⚠️ Предоплата не возвращается (отмена менее чем за 6 часов).");

        $this->send($chatId, $text);
    }

    /**
     * Отправляем пользователю сообщение с учётными данными и закрепляем его.
     * Если уже было старое сообщение — удаляем перед отправкой нового.
     * Возвращает true при успехе, false при ошибке (ошибка пишется в frontend.log).
     */
    public function sendCredentials(User $user): bool
    {
        $chatId = $user->telegram_chat_id;
        if (!$chatId) return false;

        $password = $this->generatePassword();

        // Удаляем старое закреплённое сообщение
        $oldMsgId = $user->tg_credentials_msg_id ?? $user->client?->tg_credentials_msg_id;
        if ($oldMsgId) {
            try {
                $this->telegram->deleteMessage([
                    'chat_id'    => $chatId,
                    'message_id' => $oldMsgId,
                ]);
            } catch (\Throwable) {}
        }

        $phone = $user->phone ?? '—';

        $text = "🔐 <b>Ваши данные для входа на сайт</b>\n\n"
            . "📱 <b>Телефон (логин):</b>\n<code>{$phone}</code>\n\n"
            . "🔑 <b>Пароль:</b>\n<code>{$password}</code>\n\n"
            . "Войти на сайт: <a href=\"https://hall.roltworld.com/login\">hall.roltworld.com/login</a>\n\n"
            . "<i>Через бот авторизация не нужна — просто нажмите кнопку ниже</i>";

        try {
            $msg = $this->telegram->sendMessage([
                'chat_id'         => $chatId,
                'text'            => $text,
                'parse_mode'      => 'HTML',
                'protect_content' => true,
                'reply_markup'    => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '🚪 Войти в личный кабинет', 'web_app' => ['url' => 'https://hall.roltworld.com/profile']],
                    ]],
                ]),
            ]);

            $msgId = $msg->messageId;

            $this->telegram->pinChatMessage([
                'chat_id'              => $chatId,
                'message_id'           => $msgId,
                'disable_notification' => true,
            ]);

            $user->update([
                'client_password'       => $password,
                'tg_credentials_msg_id' => $msgId,
            ]);

            // Синхронизируем с clients если есть
            if ($client = $user->client) {
                $client->update([
                    'client_password'       => $password,
                    'tg_credentials_msg_id' => $msgId,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            $line = '[' . date('Y-m-d H:i:s') . '] credentials.send_failed user=' . $user->id
                . ' chat=' . $chatId . ' error=' . $e->getMessage() . "\n";
            file_put_contents(storage_path('logs/frontend.log'), $line, FILE_APPEND | LOCK_EX);
            return false;
        }
    }

    private function generatePassword(): string
    {
        $lower   = 'abcdefghjkmnpqrstuvwxyz';
        $upper   = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $special = '!@#$%&*';
        $all     = $lower . $upper . $digits . $special;
        $length  = random_int(8, 12);

        // Гарантируем минимум по одному символу каждого типа
        $pwd = $lower[random_int(0, strlen($lower)-1)]
             . $upper[random_int(0, strlen($upper)-1)]
             . $digits[random_int(0, strlen($digits)-1)]
             . $special[random_int(0, strlen($special)-1)];

        for ($i = 4; $i < $length; $i++) {
            $pwd .= $all[random_int(0, strlen($all) - 1)];
        }

        // Перемешиваем чтобы гарантированные символы не были всегда в начале
        $arr = mb_str_split($pwd);
        shuffle($arr);
        return implode('', $arr);
    }

    /**
     * Отправляем произвольный текст администратору
     */
    public function sendRaw(string $text): void
    {
        $this->sendToAdmin($text);
    }

    /**
     * Тестовое сообщение для проверки настройки
     */
    public function sendTestMessage(): void
    {
        $this->sendToAdmin("🤖 <b>RoltHall Bot</b> подключён и готов к работе!\n\nЧат: <code>{$this->adminChatId}</code>");
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function sendToAdmin(string $text): void
    {
        $this->send($this->adminChatId, $text, $this->adminThreadId);
    }

    private function send(int|string $chatId, string $text, ?int $threadId = null): void
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
            Log::error('Telegram notification error', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновляем аватар пользователя через Telegram Bot API
     */
    public function refreshAvatar(User $user): void
    {
        $chatId = $user->telegram_chat_id;
        if (!$chatId) return;

        try {
            $photos = $this->telegram->getUserProfilePhotos([
                'user_id' => $chatId,
                'limit'   => 1,
            ])->toArray();

            $sizes  = $photos['photos'][0] ?? [];
            $photo  = $sizes[1] ?? $sizes[0] ?? null;
            $fileId = $photo['file_id'] ?? null;
            if (!$fileId) return;

            $filePath = $this->telegram->getFile(['file_id' => $fileId])->file_path ?? null;
            if (!$filePath) return;

            $token = config('services.telegram.bot_token');
            $user->update(['telegram_avatar_url' => "https://api.telegram.org/file/bot{$token}/{$filePath}"]);
        } catch (\Throwable $e) {
            Log::error('TG avatar refresh error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }
}
