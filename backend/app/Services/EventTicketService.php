<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

class EventTicketService
{
    private TelegramApi  $telegram;
    private MaxBotService $max;

    public function __construct()
    {
        $this->telegram = new TelegramApi(config('services.telegram.bot_token'));
        $this->max      = new MaxBotService();
    }

    /**
     * Полный цикл уведомлений после регистрации (или после оплаты)
     */
    public function sendRegistrationNotifications(EventRegistration $reg, Event $event): void
    {
        // Уведомление администратору
        $this->notifyAdmin($reg, $event);

        // Уведомление участнику + билет
        if ($reg->tg_user_id) {
            $this->sendTicketTg($reg, $event);
        }
        if ($reg->max_user_id) {
            $this->sendTicketMax($reg, $event);
        }

        $reg->update(['ticket_sent' => true]);
    }

    // ── Admin notifications ───────────────────────────────────────────────

    private function notifyAdmin(EventRegistration $reg, Event $event): void
    {
        $ms = $event->messenger_settings ?? [];

        $text = $this->renderTemplate(
            $ms['tg']['admin_template'] ?? null,
            $this->defaultAdminTemplate($reg, $event),
            $reg,
            $event
        );

        $tgEnabled = !empty($ms['tg']['enabled']) && !empty($ms['tg']['admin_chat_id']);
        if ($tgEnabled) {
            $params = [
                'chat_id'    => $ms['tg']['admin_chat_id'],
                'text'       => $text,
                'parse_mode' => 'HTML',
            ];
            if (!empty($ms['tg']['admin_thread_id'])) {
                $params['message_thread_id'] = (int) $ms['tg']['admin_thread_id'];
            }
            try {
                $this->telegram->sendMessage($params);
            } catch (\Throwable $e) {
                Log::error('Event admin TG notify', ['error' => $e->getMessage()]);
            }
        }

        $maxEnabled = !empty($ms['max']['enabled']) && !empty($ms['max']['admin_chat_id']);
        Log::info('Event notifyAdmin max', [
            'reg_id'      => $reg->id,
            'max_enabled' => $maxEnabled,
            'admin_chat'  => $ms['max']['admin_chat_id'] ?? null,
            'has_token'   => !empty($this->max->hasToken()),
        ]);
        if ($maxEnabled) {
            $maxText = $this->renderTemplate($ms['max']['admin_template'] ?? null, $text, $reg, $event);
            $ok = $this->max->sendToChat($ms['max']['admin_chat_id'], $maxText);
            Log::info('Event notifyAdmin max result', ['reg_id' => $reg->id, 'ok' => $ok]);
        }
    }

    private function defaultAdminTemplate(EventRegistration $reg, Event $event): string
    {
        $tariffLine  = $reg->tariff ? "\n🎫 <b>Тариф:</b> {$reg->tariff->name}" . ($reg->selected_option ? " ({$reg->selected_option})" : '') : '';
        $paymentLine = $event->payments_enabled
            ? "\n💰 <b>Оплата:</b> " . ($reg->payment_status === 'paid' ? "{$reg->payment_amount} ₽ ✅" : "ожидает")
            : '';
        $fieldsLine  = '';
        if (!empty($reg->fields_data)) {
            $fieldLabels = $event->fields->keyBy('slug')->map(fn($f) => $f->label);
            foreach ($reg->fields_data as $slug => $value) {
                $label = $fieldLabels[$slug] ?? $slug;
                $fieldsLine .= "\n• <b>{$label}:</b> {$value}";
            }
        }

        return "🎉 <b>Новая регистрация — {$event->title}</b>\n\n"
            . "📞 <b>Телефон:</b> {$reg->phone}"
            . $tariffLine
            . $paymentLine
            . ($fieldsLine ? "\n\n<b>Анкета:</b>" . $fieldsLine : '')
            . "\n\n🆔 #reg{$reg->id}";
    }

    // ── Ticket to participant ─────────────────────────────────────────────

    private function sendTicketTg(EventRegistration $reg, Event $event): void
    {
        $ms       = $event->messenger_settings ?? [];
        $tgEnabled = !empty($ms['tg']['enabled']);
        if (!$tgEnabled) return;

        $chatId   = $reg->tg_user_id;
        $userText = $this->renderTemplate(
            $ms['tg']['user_template'] ?? null,
            $this->defaultUserTemplate($reg, $event),
            $reg,
            $event
        );

        try {
            // Отправляем афишу с подписью если она есть
            $posterPath = $event->poster_path
                ? storage_path('app/public/' . $event->poster_path)
                : null;

            if ($posterPath && file_exists($posterPath)) {
                // Генерируем билет: афиша + текст участника
                $ticketPath = $this->generateTicketImage($posterPath, $reg, $event);

                $this->telegram->sendPhoto([
                    'chat_id'    => $chatId,
                    'photo'      => new \CURLFile($ticketPath),
                    'caption'    => $userText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            ['text' => '👤 Личный кабинет', 'web_app' => ['url' => 'https://hall.roltworld.com/profile']],
                        ]],
                    ]),
                ]);

                @unlink($ticketPath);
            } else {
                $this->telegram->sendMessage([
                    'chat_id'    => $chatId,
                    'text'       => $userText,
                    'parse_mode' => 'HTML',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Event ticket TG send', ['reg_id' => $reg->id, 'error' => $e->getMessage()]);
        }
    }

    private function sendTicketMax(EventRegistration $reg, Event $event): void
    {
        $ms        = $event->messenger_settings ?? [];
        $maxEnabled = !empty($ms['max']['enabled']);
        if (!$maxEnabled) return;

        $userId   = $reg->max_user_id;
        $userText = $this->renderTemplate(
            $ms['max']['user_template'] ?? null,
            $this->defaultUserTemplate($reg, $event),
            $reg,
            $event
        );

        // Max API не поддерживает отправку файлов через user_id — только текст
        $this->max->sendMessage($userId, $userText, [[
            ['type' => 'link', 'text' => '👤 Личный кабинет', 'url' => 'https://hall.roltworld.com/profile?via=max'],
        ]]);
    }

    private function defaultUserTemplate(EventRegistration $reg, Event $event): string
    {
        $dateStr    = $event->event_date?->format('d.m.Y') ?? '—';
        $tariffLine = $reg->tariff
            ? "\n🎫 <b>Тариф:</b> {$reg->tariff->name}" . ($reg->selected_option ? " ({$reg->selected_option})" : '')
            : '';

        return "🎉 <b>Вы зарегистрированы!</b>\n\n"
            . "📅 <b>Событие:</b> {$event->title}\n"
            . "🗓 <b>Дата:</b> {$dateStr}"
            . $tariffLine . "\n\n"
            . "🆔 Ваш билет: <code>#{$reg->id}</code>\n\n"
            . "Ждём вас!";
    }

    // ── GD ticket generation ──────────────────────────────────────────────

    private function generateTicketImage(string $posterPath, EventRegistration $reg, Event $event): string
    {
        $src = null;
        $ext = strtolower(pathinfo($posterPath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $src = @imagecreatefrompng($posterPath);
        } else {
            $src = @imagecreatefromjpeg($posterPath);
        }

        if (!$src) {
            // Если не удалось открыть — возвращаем оригинал
            return $posterPath;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Полупрозрачная полоса снизу 20% высоты
        $barH = (int)($h * 0.22);
        $barY = $h - $barH;

        $img = imagecreatetruecolor($w, $h);
        imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        // Тёмный фон полосы (rgba через imagefilledrectangle с alpha)
        $overlay = imagecreatetruecolor($w, $barH);
        $bg      = imagecolorallocate($overlay, 20, 20, 25);
        imagefilledrectangle($overlay, 0, 0, $w, $barH, $bg);
        imagecopymerge($img, $overlay, 0, $barY, 0, 0, $w, $barH, 80);
        imagedestroy($overlay);

        // Текст
        $white  = imagecolorallocate($img, 255, 255, 255);
        $gray   = imagecolorallocate($img, 180, 180, 190);
        $accent = imagecolorallocate($img, 55, 111, 255);

        $fontSize = max(12, (int)($w / 45));
        $x        = (int)($w * 0.04);
        $lineH    = (int)($fontSize * 1.6);
        $y        = $barY + (int)($barH * 0.12);

        // Название события
        imagestring($img, min(5, $fontSize > 14 ? 5 : 4), $x, $y, mb_substr($event->title, 0, 40), $white);
        $y += $lineH;

        // Дата
        $dateStr = $event->event_date?->format('d.m.Y') ?? '';
        if ($dateStr) {
            imagestring($img, 3, $x, $y, $dateStr, $gray);
            $y += $lineH;
        }

        // Тариф
        if ($reg->tariff) {
            $tariffStr = $reg->tariff->name . ($reg->selected_option ? " · {$reg->selected_option}" : '');
            imagestring($img, 3, $x, $y, $tariffStr, $gray);
            $y += $lineH;
        }

        // Номер билета — справа
        $codeStr  = '#' . str_pad($reg->id, 6, '0', STR_PAD_LEFT);
        $codeFont = min(5, $fontSize > 14 ? 5 : 4);
        $codeW    = strlen($codeStr) * imagefontwidth($codeFont);
        imagestring($img, $codeFont, $w - $codeW - $x, $barY + (int)($barH * 0.12), $codeStr, $accent);

        $tmpPath = sys_get_temp_dir() . '/ticket_' . $reg->id . '_' . time() . '.jpg';
        imagejpeg($img, $tmpPath, 90);
        imagedestroy($img);

        return $tmpPath;
    }

    // ── Template renderer ─────────────────────────────────────────────────

    private function renderTemplate(?string $template, string $default, EventRegistration $reg, Event $event): string
    {
        if (!$template) return $default;

        $tariffName = $reg->tariff?->name ?? '—';
        $option     = $reg->selected_option ?? '—';
        $dateStr    = $event->event_date?->format('d.m.Y') ?? '—';

        $displayName = '';
        if (!empty($reg->fields_data)) {
            $displayName = array_values($reg->fields_data)[0] ?? '';
        }

        $searches = ['{name}', '{phone}', '{event}', '{date}', '{tariff}', '{option}', '{reg_id}', '{code}'];
        $replaces = [$displayName, $reg->phone, $event->title, $dateStr, $tariffName, $option, $reg->id, '#' . str_pad($reg->id, 6, '0', STR_PAD_LEFT)];

        foreach ($reg->fields_data ?? [] as $slug => $value) {
            $searches[] = '{cf_' . $slug . '}';
            $replaces[] = $value ?? '';
        }

        return str_replace($searches, $replaces, $template);
    }
}
