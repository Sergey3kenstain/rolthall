<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\EventTicketService;
use App\Services\TBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventPublicController extends Controller
{
    public function __construct(
        private TBankService       $tbank,
        private EventTicketService $tickets,
    ) {}

    /**
     * Публичные данные события для отображения формы
     * GET /api/events/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $event = Event::with(['fields', 'tariffs.tiers'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Текущие цены тарифов
        $tariffs = $event->tariffs->map(fn($t) => [
            'id'               => $t->id,
            'name'             => $t->name,
            'has_options'      => $t->has_options,
            'options'          => $t->options,
            'dependent_fields' => $t->dependent_fields ?? [],
            'current_price'    => $t->currentPrice(),
            'tiers'        => $t->tiers->map(fn($tier) => [
                'from_date' => $tier->from_date->format('d.m.Y'),
                'to_date'   => $tier->to_date->format('d.m.Y'),
                'price'     => $tier->price,
            ]),
        ]);

        return response()->json([
            'ok'          => true,
            'id'          => $event->id,
            'title'       => $event->title,
            'description' => $event->description,
            'form_css'    => $event->form_css,
            'poster_url'  => $event->poster_url,
            'event_date'  => $event->event_date?->format('d.m.Y'),
            'payments_enabled'             => $event->payments_enabled,
            'allow_multiple_registrations' => $event->allow_multiple_registrations,
            'fields'      => $event->fields,
            'tariffs'     => $tariffs,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Регистрация участника
     * POST /api/events/{slug}/register
     */
    public function register(Request $request, string $slug): JsonResponse
    {
        // Медовый горшок (honeypot)
        if ($request->input('_hp') !== null && $request->input('_hp') !== '') {
            return response()->json(['ok' => false, 'error' => 'Bad request'], 422);
        }

        $event = Event::with(['fields', 'tariffs.tiers'])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $data = $request->validate([
            'phone'           => 'required|string|max:30',
            'tg_user_id'      => 'nullable|string|max:50',
            'max_user_id'     => 'nullable|string|max:50',
            'fields_data'     => 'nullable|array',
            'tariff_id'       => 'nullable|integer|exists:event_tariffs,id',
            'selected_option' => 'nullable|string|max:191',
        ]);

        // Проверка повторной регистрации
        if (!$event->allow_multiple_registrations && $data['tg_user_id']) {
            $exists = EventRegistration::where('event_id', $event->id)
                ->where('tg_user_id', $data['tg_user_id'])
                ->whereIn('payment_status', ['free', 'pending', 'paid'])
                ->exists();
            if ($exists) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'already_registered',
                ], 422, [], JSON_UNESCAPED_UNICODE);
            }
        }

        // Определяем цену тарифа
        $tariff      = null;
        $price       = 0;
        $orderId     = null;
        $paymentUrl  = null;

        if ($event->payments_enabled && $data['tariff_id']) {
            $tariff = $event->tariffs->find($data['tariff_id']);
            if (!$tariff) {
                return response()->json(['ok' => false, 'error' => 'Тариф не найден'], 422);
            }
            $price = $tariff->currentPrice();
            if ($price === null) {
                return response()->json(['ok' => false, 'error' => 'Тариф недоступен на сегодняшнюю дату'], 422);
            }
            $orderId = 'ev-' . $event->id . '-' . Str::random(12);
        }

        // Создаём регистрацию
        $reg = EventRegistration::create([
            'event_id'        => $event->id,
            'phone'           => $data['phone'],
            'tg_user_id'      => $data['tg_user_id'] ?? null,
            'max_user_id'     => $data['max_user_id'] ?? null,
            'fields_data'     => $data['fields_data'] ?? [],
            'tariff_id'       => $tariff?->id,
            'selected_option' => $data['selected_option'] ?? null,
            'payment_status'  => $event->payments_enabled && $price > 0 ? 'pending' : 'free',
            'payment_amount'  => $price,
            'payment_order_id'=> $orderId,
            'client_id'       => $this->resolveClientId($data['phone'], $event->tag),
        ]);

        // Инициируем платёж если нужен
        if ($event->payments_enabled && $price > 0) {
            $base = config('app.url');
            $result = $this->tbank->init([
                'amount'           => $price * 100,
                'order_id'         => $orderId,
                'description'      => "Событие «{$event->title}» — {$tariff->name}",
                'phone'            => $data['phone'],
                'notification_url' => $base . '/api/events/payment-webhook',
                'success_url'      => $base . '/event-success.html?reg=' . $reg->id . '&paid=1',
                'fail_url'         => $base . '/event-form.html?id=' . $event->slug . '&payment_failed=1',
            ]);

            if (!$result['ok']) {
                $reg->delete();
                return response()->json(['ok' => false, 'error' => $result['error']], 422);
            }

            $reg->update(['tbank_payment_id' => $result['PaymentId'] ?? null]);
            $paymentUrl = $result['PaymentURL'];
        } else {
            // Бесплатная регистрация — сразу отправляем уведомления
            $this->tickets->sendRegistrationNotifications($reg, $event);
        }

        return response()->json([
            'ok'          => true,
            'reg_id'      => $reg->id,
            'payment_url' => $paymentUrl,
            'is_free'     => !$event->payments_enabled || $price === 0,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Webhook T-Bank для оплаты событий
     * POST /api/events/payment-webhook
     */
    public function paymentWebhook(Request $request): JsonResponse
    {
        $data = $request->all();

        // Проверка токена T-Bank (тот же механизм что в PaymentController)
        $token     = $data['Token'] ?? '';
        $orderId   = $data['OrderId'] ?? '';
        $status    = $data['Status'] ?? '';
        $paymentId = (string) ($data['PaymentId'] ?? '');

        if ($status !== 'CONFIRMED') {
            return response()->json(['ok' => true]);
        }

        $reg = EventRegistration::where('payment_order_id', $orderId)->first();
        if (!$reg || $reg->payment_status === 'paid') {
            return response()->json(['ok' => true]);
        }

        $reg->update([
            'payment_status'   => 'paid',
            'tbank_payment_id' => $paymentId,
            'paid_at'          => now(),
        ]);

        $event = $reg->event()->with(['fields', 'tariffs'])->first();
        $this->tickets->sendRegistrationNotifications($reg, $event);

        return response()->json(['ok' => true]);
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function resolveClientId(string $phone, ?string $eventTag): ?int
    {
        $normalized = preg_replace('/[^\d]/', '', $phone);
        $last10 = substr($normalized, -10);
        $client = Client::whereHas('user', fn($q) =>
            $q->where('phone', 'like', "%{$last10}")
        )->first();

        if ($client && $eventTag) {
            $tags   = $client->event_tags ?? [];
            if (!in_array($eventTag, $tags, true)) {
                $tags[] = $eventTag;
                $client->update(['event_tags' => $tags]);
            }
        }

        return $client?->id;
    }
}
