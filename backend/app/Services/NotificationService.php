<?php

namespace App\Services;

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
        $text = "🏠 <b>Новая бронь — RoltHall</b>\n\n"
            . "👤 <b>ФИО:</b> {$data['client_name']}\n"
            . "📋 <b>Формат:</b> Разовая аренда\n"
            . "📅 <b>Дата, время:</b> {$data['date']}, {$data['time_start']}–{$data['time_end']}\n"
            . "🏛 <b>Зал:</b> {$data['hall_name']}\n"
            . "📞 <b>Телефон:</b> {$data['phone']}\n"
            . "💬 <b>Телеграм:</b> @{$data['telegram']}\n\n"
            . "✅ <b>Положение принято:</b> Да\n\n"
            . "💰 <b>Оплачено:</b> {$data['prepayment']} RUB\n"
            . "🔑 <b>ИД транзакции:</b> {$data['transaction_id']}";

        $this->sendToAdmin($text);
    }

    /**
     * Уведомление клиенту о подтверждении брони
     */
    public function notifyClientConfirmed(int|string $chatId, array $data): void
    {
        $text = "✅ <b>Бронь подтверждена!</b>\n\n"
            . "🏛 <b>Зал:</b> {$data['hall_name']}\n"
            . "📅 <b>Дата:</b> {$data['date']}\n"
            . "🕐 <b>Время:</b> {$data['time_start']}–{$data['time_end']}\n"
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
}
