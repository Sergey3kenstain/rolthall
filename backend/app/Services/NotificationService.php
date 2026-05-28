<?php

namespace App\Services;

use App\Models\ActionLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

class NotificationService
{
    private TelegramApi  $telegram;
    private MaxBotService $max;
    private string|int   $adminChatId;
    private ?int         $adminThreadId;
    private ?string      $maxAdminChatId;
    private ?string      $maxAdminGroupChatId;

    public function __construct()
    {
        $maxFile              = storage_path('app/max_settings.json');
        $maxSettings          = file_exists($maxFile) ? (json_decode(file_get_contents($maxFile), true) ?? []) : [];
        $this->telegram       = new TelegramApi(config('services.telegram.bot_token'));
        $this->max            = new MaxBotService();
        $this->adminChatId    = config('services.telegram.admin_chat_id');
        $this->adminThreadId  = config('services.telegram.admin_thread_id') ?: null;
        $this->maxAdminChatId      = $maxSettings['admin_chat_id']       ?? config('services.max.admin_chat_id') ?: null;
        $this->maxAdminGroupChatId = $maxSettings['admin_group_chat_id'] ?? null;
    }

    /**
     * Уведомление администратору о новой брони
     */
    public function notifyAdminNewBooking(array $data): void
    {
        $baseLabel = match ($data['format'] ?? 'hourly') {
            'event'  => 'Событие',
            'allday' => 'Весь день',
            default  => 'Почасовая',
        };
        $formatLabel = !empty($data['pkg_label']) ? $data['pkg_label'] : $baseLabel;

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

        // TG — через общий канал
        $tgOk = $this->send($this->adminChatId, $text, $this->adminThreadId);
        $this->logNotif('tg', 'new_booking', 'admin', $tgOk);

        // Max — с поддержкой шаблона
        if ($this->maxAdminChatId || $this->maxAdminGroupChatId) {
            $vars = [
                'name'     => $data['client_name'],
                'phone'    => $data['phone'],
                'email'    => $data['email'] ?? '—',
                'telegram' => $data['telegram'] ?? '—',
                'date'     => $data['date'],
                'time'     => ($data['time_start'] ?? '') . '–' . ($data['time_end'] ?? ''),
                'hall'     => $data['hall_name'],
                'format'   => $formatLabel,
                'amount'   => $data['prepayment'] ?? 0,
                'txn_id'   => $data['transaction_id'] ?? '—',
                'guests'   => (!empty($data['guest_count']) && $data['guest_count'] > 0) ? $data['guest_count'] : '—',
            ];
            $maxText = $this->renderMaxTemplate('new_booking', $vars, $text);
            if ($this->maxAdminChatId) {
                $ok = $this->max->sendMessage($this->maxAdminChatId, $maxText);
                $this->logNotif('max', 'new_booking', 'admin', $ok);
            }
            if ($this->maxAdminGroupChatId) {
                $ok = $this->max->sendToChat($this->maxAdminGroupChatId, $maxText);
                $this->logNotif('max', 'new_booking', 'admin_group', $ok);
            }
        }
    }

    /**
     * Уведомление клиенту о подтверждении брони (TG + Max)
     */
    public function notifyClientConfirmedDual(?string $chatId, ?string $maxChatId, array $data): void
    {
        if ($chatId) $this->notifyClientConfirmed($chatId, $data);
        if ($maxChatId) $this->notifyClientConfirmedMax($maxChatId, $data);
    }

    private function notifyClientConfirmedMax(string $maxChatId, array $data): void
    {
        $guestsLine = !empty($data['guest_count']) && $data['guest_count'] > 0
            ? "👥 <b>Гостей:</b> {$data['guest_count']} чел.\n"
            : '';

        $default = "✅ <b>Бронь подтверждена!</b>\n\n"
            . "🏛 <b>Зал:</b> {$data['hall_name']}\n"
            . "📅 <b>Дата:</b> {$data['date']}\n"
            . "🕐 <b>Время:</b> {$data['time_start']}–{$data['time_end']}\n"
            . $guestsLine
            . "💰 <b>Предоплата:</b> {$data['prepayment']} ₽\n\n"
            . "Ждём вас! По вопросам — свяжитесь с нами.";

        $text = $this->renderMaxTemplate('booking_confirmed', [
            'hall'   => $data['hall_name'],
            'date'   => $data['date'],
            'time'   => $data['time_start'] . '–' . $data['time_end'],
            'amount' => $data['prepayment'] ?? 0,
            'guests' => (!empty($data['guest_count']) && $data['guest_count'] > 0) ? $data['guest_count'] : '—',
        ], $default);

        $ok = $this->max->sendMessage($maxChatId, $text);
        $this->logNotif('max', 'booking_confirmed', $this->maskId($maxChatId), $ok);
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

        $ok = $this->send($chatId, $text);
        $this->logNotif('tg', 'booking_confirmed', $this->maskId((string)$chatId), $ok);
    }

    /**
     * Напоминание клиенту — dual TG + Max
     */
    public function notifyClientReminder3hDual(?string $chatId, ?string $maxChatId, array $data): void
    {
        if ($chatId) $this->notifyClientReminder3h($chatId, $data);
        if ($maxChatId) $this->notifyClientReminder3hMax($maxChatId, $data);
    }

    private function notifyClientReminder3hMax(string $maxChatId, array $data): void
    {
        if (!empty($data['allday'])) {
            $default = "⏰ <b>Напоминание</b>\n\n"
                . "Завтра у вас бронь в RoltHall!\n"
                . "🏛 {$data['hall_name']} · {$data['date']}, весь день (09:00–22:00)\n\n"
                . "Ждём вас!";
            $text = $this->renderMaxTemplate('reminder_allday', [
                'hall' => $data['hall_name'],
                'date' => $data['date'],
            ], $default);
        } else {
            $default = "⏰ <b>Напоминание</b>\n\n"
                . "Через 3 часа ваша бронь в RoltHall!\n"
                . "🏛 {$data['hall_name']} · {$data['date']}, {$data['time_start']}–{$data['time_end']}\n\n"
                . "Отмена возможна не позднее чем за 3 часа до начала.";
            $text = $this->renderMaxTemplate('reminder_3h', [
                'hall' => $data['hall_name'],
                'date' => $data['date'],
                'time' => $data['time_start'] . '–' . $data['time_end'],
            ], $default);
        }

        $event = !empty($data['allday'] ?? false) ? 'reminder_allday' : 'reminder_3h';
        $ok = $this->max->sendMessage($maxChatId, $text);
        $this->logNotif('max', $event, $this->maskId($maxChatId), $ok);
    }

    /**
     * Напоминание клиенту — за 3ч (почасовая) или накануне в 20:00 (весь день)
     */
    public function notifyClientReminder3h(int|string $chatId, array $data): void
    {
        if (!empty($data['allday'])) {
            $text = "⏰ <b>Напоминание</b>\n\n"
                . "Завтра у вас бронь в RoltHall!\n"
                . "🏛 {$data['hall_name']} · {$data['date']}, весь день (09:00–22:00)\n\n"
                . "Ждём вас!";
        } else {
            $text = "⏰ <b>Напоминание</b>\n\n"
                . "Через 3 часа ваша бронь в RoltHall!\n"
                . "🏛 {$data['hall_name']} · {$data['date']}, {$data['time_start']}–{$data['time_end']}\n\n"
                . "Отмена возможна не позднее чем за 3 часа до начала.";
        }

        $event = !empty($data['allday']) ? 'reminder_allday' : 'reminder_3h';
        $ok = $this->send($chatId, $text);
        $this->logNotif('tg', $event, $this->maskId((string)$chatId), $ok);
    }

    /**
     * Уведомление об отмене клиенту — dual TG + Max
     */
    public function notifyClientCancelledDual(?string $chatId, ?string $maxChatId, bool $refunded): void
    {
        if ($chatId)    $this->notifyClientCancelled($chatId, $refunded);
        if ($maxChatId) $this->notifyClientCancelledMax($maxChatId, $refunded);
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

        $ok = $this->send($chatId, $text);
        $this->logNotif('tg', 'booking_cancelled', $this->maskId((string)$chatId), $ok);
    }

    private function notifyClientCancelledMax(string $maxChatId, bool $refunded): void
    {
        $refundLine = $refunded
            ? 'Предоплата будет возвращена в течение 3–5 рабочих дней.'
            : 'Предоплата не возвращается (отмена менее чем за 6 часов).';

        $default = "❌ <b>Бронь отменена</b>\n\n{$refundLine}";

        $text = $this->renderMaxTemplate('booking_cancelled', [
            'refund_info' => $refundLine,
        ], $default);

        $ok = $this->max->sendMessage($maxChatId, $text);
        $this->logNotif('max', 'booking_cancelled', $this->maskId($maxChatId), $ok);
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

    // ─── Max template helper ──────────────────────────────────────────────────

    private function renderMaxTemplate(string $key, array $vars, string $default): string
    {
        $file     = storage_path('app/max_settings.json');
        $settings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $tpl      = $settings['templates'][$key] ?? null;
        if (!$tpl) return $default;
        $search  = array_map(fn($k) => "{{$k}}", array_keys($vars));
        return str_replace($search, array_values($vars), $tpl);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function sendToAdmin(string $text): void
    {
        $tgOk = $this->send($this->adminChatId, $text, $this->adminThreadId);
        $this->logNotif('tg', 'raw', 'admin', $tgOk);

        if ($this->maxAdminChatId) {
            $ok = $this->max->sendMessage($this->maxAdminChatId, $text);
            $this->logNotif('max', 'raw', 'admin', $ok);
        }
        if ($this->maxAdminGroupChatId) {
            $ok = $this->max->sendToChat($this->maxAdminGroupChatId, $text);
            $this->logNotif('max', 'raw', 'admin_group', $ok);
        }
    }

    private function send(int|string $chatId, string $text, ?int $threadId = null): bool
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
            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram notification error', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function logNotif(string $channel, string $event, string $recipient, bool $ok): void
    {
        ActionLog::write(
            'notification.sent',
            null,
            'system',
            null,
            null,
            ['channel' => $channel, 'event' => $event, 'to' => $recipient, 'ok' => $ok]
        );
    }

    private function maskId(string $id): string
    {
        $len = strlen($id);
        if ($len <= 4) return $id;
        return str_repeat('*', $len - 4) . substr($id, -4);
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
