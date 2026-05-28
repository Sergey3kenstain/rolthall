<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Hall;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\TBankService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private TBankService   $tbank,
    ) {}

    private function isOwner(Request $r): bool
    {
        return $r->user()->hasAnyRole(['owner', 'developer']);
    }

    private function isDeveloper(Request $r): bool
    {
        return $r->user()->hasRole('developer');
    }

    // ── Hall ──────────────────────────────────────────────────────────────

    public function halls(?int $id = null): JsonResponse
    {
        if ($id) {
            $hall = Hall::with('pricingRules')->findOrFail($id);
            return response()->json($hall, 200, [], JSON_UNESCAPED_UNICODE);
        }
        return response()->json(Hall::with('pricingRules')->get(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function createHall(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) abort(403);

        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'description'    => 'nullable|string',
            'area_m2'        => 'nullable|integer',
            'capacity'       => 'nullable|integer',
            'equipment'      => 'nullable|array',
            'equipment.*'    => 'string|max:100',
            'buffer_minutes' => 'nullable|integer',
            'rules'          => 'nullable|string',
            'contact_phone'  => 'nullable|string|max:30',
            'is_active'      => 'boolean',
        ]);

        $hall = Hall::create(array_merge(['is_active' => true], $data));
        return response()->json(['ok' => true, 'hall' => $hall->load('pricingRules')], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateHall(Request $request, int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:191',
            'description'    => 'sometimes|nullable|string',
            'area_m2'        => 'sometimes|nullable|integer',
            'capacity'       => 'sometimes|nullable|integer',
            'equipment'      => 'sometimes|nullable|array',
            'equipment.*'    => 'string|max:100',
            'buffer_minutes' => 'sometimes|nullable|integer',
            'rules'          => 'sometimes|nullable|string',
            'contact_phone'  => 'sometimes|nullable|string|max:30',
            'is_active'      => 'sometimes|boolean',
        ]);
        $hall->update($data);
        return response()->json(['ok' => true, 'hall' => $hall->fresh('pricingRules')], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function deleteHall(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) abort(403);
        Hall::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    // ── Hall CMS publish ─────────────────────────────────────────────────

    public function publishHall(Request $request, int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);
        $cms  = $request->validate(['cms' => 'required|array'])['cms'];

        // 1. Сохраняем CMS в БД
        $hall->update(['cms' => $cms]);

        // 2. firstOrCreate pricing_rules из CMS (hourly — с guest_tier из строки, event_table → event)
        foreach ($cms['pricing']['hourly'] ?? [] as $row) {
            if (empty($row['engine'])) continue;
            $tier = $row['guest_tier'] ?? 'below30';
            $rule = PricingRule::firstOrCreate(
                ['hall_id' => $hall->id, 'booking_format' => 'hourly', 'day_type' => $row['day_type'], 'guest_tier' => $tier],
                ['price_per_hour' => (int) $row['price'], 'description' => $row['desc'] ?? null, 'is_active' => true, 'min_hours' => 1]
            );
            // Всегда обновляем description из CMS (цену не трогаем — её меняют через engine pricing)
            if (!empty($row['desc'])) {
                $rule->update(['description' => $row['desc']]);
            }
        }
        foreach ($cms['pricing']['event_table'] ?? [] as $row) {
            if (empty($row['engine'])) continue;
            PricingRule::firstOrCreate(
                ['hall_id' => $hall->id, 'booking_format' => 'event', 'day_type' => $row['day_type'], 'guest_tier' => 'any'],
                ['price_per_hour' => (int) $row['price'], 'description' => $row['desc'] ?? null, 'is_active' => true]
            );
        }

        // 3. Обратная синхронизация: обновляем cms из актуального engine pricing
        $below30 = PricingRule::where('hall_id', $hall->id)
            ->where('booking_format', 'hourly')->where('guest_tier', 'below30')->where('is_active', true)
            ->get()->keyBy('day_type');
        $eventRules = PricingRule::where('hall_id', $hall->id)
            ->where('booking_format', 'event')->where('is_active', true)
            ->get()->keyBy('day_type');

        if (!empty($cms['pricing']['hourly'])) {
            $cms['pricing']['hourly'] = array_map(function ($row) use ($below30) {
                if (empty($row['engine'])) return $row;
                $dt   = $row['day_type'] ?? null;
                $rule = $dt ? ($below30[$dt] ?? null) : null;
                if ($rule && ($row['desc'] ?? '') === ($rule->description ?? '')) {
                    $row['price'] = $rule->price_per_hour;
                }
                return $row;
            }, $cms['pricing']['hourly']);
        }
        if (!empty($cms['pricing']['event_table'])) {
            $cms['pricing']['event_table'] = array_map(function ($row) use ($eventRules) {
                if (empty($row['engine'])) return $row;
                $dt   = $row['day_type'] ?? null;
                $rule = $dt ? ($eventRules[$dt] ?? null) : null;
                if ($rule) $row['price'] = $rule->price_per_hour;
                return $row;
            }, $cms['pricing']['event_table']);
        }
        $hall->update(['cms' => $cms]);

        // 4. Генерируем cms_data.js
        $allRules = PricingRule::where('hall_id', $hall->id)->where('is_active', true)->get()
            ->map(fn($r) => [
                'booking_format' => $r->booking_format,
                'day_type'       => $r->day_type,
                'guest_tier'     => $r->guest_tier,
                'description'    => $r->description,
                'price'          => $r->price_per_hour ?? $r->price_per_day,
            ]);

        $payload = array_merge($cms, ['pricing_rules' => $allRules]);
        $js = 'window.HALL_CMS=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        $dir = config('cms.public_dir', public_path());
        file_put_contents(rtrim($dir, '/') . '/cms_data.js', $js);

        return response()->json(['ok' => true]);
    }

    // ── Telegram settings ────────────────────────────────────────────────

    public function telegramSettings(Request $request): JsonResponse
    {
        $file      = storage_path('app/telegram_settings.json');
        $saved     = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $isDev     = $request->user()->hasRole('developer');

        $result = [
            'ok'            => true,
            'templates'     => $saved['templates']     ?? null,
            'cmd_templates' => $saved['cmd_templates'] ?? null,
        ];

        if ($isDev) {
            $result['token']             = $saved['token']             ?? config('services.telegram.bot_token');
            $result['chat_id']           = $saved['chat_id']           ?? config('services.telegram.admin_chat_id');
            $result['thread_id']         = $saved['thread_id']         ?? config('services.telegram.admin_thread_id');
            $result['test_client_tg_id'] = $saved['test_client_tg_id'] ?? null;
        }

        return response()->json($result);
    }

    public function saveTelegramSettings(Request $request): JsonResponse
    {
        $file      = storage_path('app/telegram_settings.json');
        $existing  = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $isDev     = $request->user()->hasRole('developer');

        $rules = [];
        if ($isDev) {
            if ($request->has('token'))             $rules['token']             = 'required|string';
            if ($request->has('chat_id'))           $rules['chat_id']           = 'required|string';
            if ($request->has('thread_id'))         $rules['thread_id']         = 'nullable|string';
            if ($request->has('test_client_tg_id')) $rules['test_client_tg_id'] = 'nullable|string';
        }
        if ($request->has('templates'))    $rules['templates']    = 'nullable|array';
        if ($request->has('cmd_templates')) $rules['cmd_templates'] = 'nullable|array';

        $data   = $request->validate($rules);
        $merged = array_merge($existing, $data);
        file_put_contents($file, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return response()->json(['ok' => true]);
    }

    public function testTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'  => 'required|string|in:new_booking,booking_confirmed,booking_cancelled,payment_received,reminder_3h,reschedule_request',
            'text' => 'required|string|max:4096',
        ]);

        $file     = storage_path('app/telegram_settings.json');
        $settings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

        $token    = $settings['token']             ?? config('services.telegram.bot_token');
        $chatId   = $settings['chat_id']           ?? config('services.telegram.admin_chat_id');
        $threadId = (int) ($settings['thread_id']  ?? config('services.telegram.admin_thread_id') ?? 0);
        $testClientId = $settings['test_client_tg_id'] ?? null;

        $adminKeys = ['new_booking', 'payment_received', 'reschedule_request'];
        $isAdmin   = in_array($data['key'], $adminKeys);
        $targetId  = $isAdmin ? $chatId : $testClientId;

        if (!$targetId) {
            $msg = $isAdmin
                ? 'Не задан Admin Chat ID в настройках Telegram.'
                : 'Не задан тестовый TG ID клиента.';
            return response()->json(['ok' => false, 'error' => $msg], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $dummies = [
            '{name}'     => 'Иван Иваныч',
            '{phone}'    => '+79086850838',
            '{email}'    => 'ivan@example.com',
            '{date}'     => '25.05.2026',
            '{time}'     => '10:00–22:00',
            '{hours}'    => '12',
            '{hall}'     => 'RoltHall',
            '{amount}'   => '5 000',
            '{format}'   => 'Весь день',
            '{txn_id}'   => '8541823676',
            '{telegram}'   => '@test_user',
            '{tg_id}'      => '123456789',
            '{guests}'     => '—',
            '{booking_id}' => '42',
            '{old_date}'   => '20.05.2026',
            '{old_time}'   => '10:00–22:00',
            '{new_date}'   => '28.05.2026',
            '{new_time}'   => '12:00–18:00',
            '{comment}'    => 'Изменились планы, прошу перенести',
        ];

        $text = '[ТЕСТ] ' . str_replace(array_keys($dummies), array_values($dummies), $data['text']);

        try {
            $telegram = new \Telegram\Bot\Api($token);
            $params   = ['chat_id' => $targetId, 'text' => $text, 'parse_mode' => 'HTML'];
            if ($isAdmin && $threadId) {
                $params['message_thread_id'] = $threadId;
            }
            $telegram->sendMessage($params);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422, [], JSON_UNESCAPED_UNICODE);
        }
    }

    // ── Max settings ─────────────────────────────────────────────────────

    public function maxSettings(Request $request): JsonResponse
    {
        $file  = storage_path('app/max_settings.json');
        $saved = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $isDev = $request->user()->hasRole('developer');

        $result = [
            'ok'        => true,
            'templates' => $saved['templates'] ?? null,
        ];
        if ($isDev) {
            $result['token']               = $saved['token']               ?? config('services.max.bot_token');
            $result['admin_chat_id']       = $saved['admin_chat_id']       ?? config('services.max.admin_chat_id');
            $result['admin_group_chat_id'] = $saved['admin_group_chat_id'] ?? null;
            $result['test_client_max_id']  = $saved['test_client_max_id']  ?? null;
        }

        return response()->json($result);
    }

    public function saveMaxSettings(Request $request): JsonResponse
    {
        $file     = storage_path('app/max_settings.json');
        $existing = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $isDev    = $request->user()->hasRole('developer');

        $rules = [];
        if ($isDev) {
            if ($request->has('token'))               $rules['token']               = 'required|string';
            if ($request->has('admin_chat_id'))       $rules['admin_chat_id']       = 'nullable|string';
            if ($request->has('admin_group_chat_id')) $rules['admin_group_chat_id'] = 'nullable|string';
            if ($request->has('test_client_max_id'))  $rules['test_client_max_id']  = 'nullable|string';
        }
        if ($request->has('templates')) $rules['templates'] = 'nullable|array';

        $data   = $request->validate($rules);
        $merged = array_merge($existing, $data);
        file_put_contents($file, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return response()->json(['ok' => true]);
    }

    public function testMaxTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'  => 'required|string|in:new_booking,booking_confirmed,booking_cancelled,reminder_3h,reminder_allday',
            'text' => 'required|string|max:4096',
        ]);

        $file     = storage_path('app/max_settings.json');
        $settings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

        $adminUserId    = $settings['admin_chat_id']       ?? config('services.max.admin_chat_id');
        $adminGroupId   = $settings['admin_group_chat_id'] ?? null;
        $testClientId   = $settings['test_client_max_id']  ?? null;
        $isAdmin        = in_array($data['key'], ['new_booking']);

        if ($isAdmin && !$adminUserId && !$adminGroupId) {
            return response()->json(['ok' => false, 'error' => 'Не задан Admin User ID / Chat ID в настройках Max.'], 422, [], JSON_UNESCAPED_UNICODE);
        }
        if (!$isAdmin && !$testClientId) {
            return response()->json(['ok' => false, 'error' => 'Не задан тестовый Max ID клиента.'], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $dummies = [
            '{name}'        => 'Иван Иванов',
            '{phone}'       => '+79086850838',
            '{email}'       => 'ivan@example.com',
            '{telegram}'    => 'test_user',
            '{date}'        => '28.05.2026',
            '{time}'        => '15:00–16:00',
            '{hall}'        => 'RoltHall',
            '{format}'      => 'Почасовая',
            '{amount}'      => '1 500',
            '{txn_id}'      => '8541823676',
            '{guests}'      => '5',
            '{refund_info}' => 'Предоплата будет возвращена в течение 3–5 рабочих дней.',
        ];

        $text = '[ТЕСТ] ' . str_replace(array_keys($dummies), array_values($dummies), $data['text']);
        $max  = new \App\Services\MaxBotService();

        if ($isAdmin) {
            $ok = true;
            if ($adminUserId) $ok = $max->sendMessage($adminUserId, $text) && $ok;
            if ($adminGroupId) $ok = $max->sendToChat($adminGroupId, $text) && $ok;
        } else {
            $ok = $max->sendMessage($testClientId, $text);
        }

        return response()->json($ok
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Не удалось отправить'], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function testMaxSend(Request $request): JsonResponse
    {
        $file     = storage_path('app/max_settings.json');
        $settings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];

        $adminUserId  = $settings['admin_chat_id']       ?? config('services.max.admin_chat_id') ?: null;
        $adminGroupId = $settings['admin_group_chat_id'] ?? null;

        if (!$adminUserId && !$adminGroupId) {
            return response()->json(['ok' => false, 'error' => 'Admin User ID / Chat ID не задан'], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $max  = new \App\Services\MaxBotService();
        $text = '🤖 <b>RoltHall Max Bot</b> подключён и готов к работе!';
        $ok   = true;
        if ($adminUserId)  $ok = $max->sendMessage($adminUserId, $text) && $ok;
        if ($adminGroupId) $ok = $max->sendToChat($adminGroupId, $text) && $ok;

        return response()->json($ok
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'Не удалось отправить — проверьте что бот добавлен в чат'], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── File upload ───────────────────────────────────────────────────────

    public function upload(Request $request): JsonResponse
    {
        $type    = $request->input('type', 'carousel'); // 'hero' | 'carousel'
        $maxKb   = $type === 'hero' ? 1536 : 1024;      // 1.5 MB / 1 MB
        $request->validate(['file' => "required|file|image|max:{$maxKb}"]);

        $file = $request->file('file');
        $dir  = public_path('uploads/halls');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $name = uniqid($type.'_', true) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $name);

        return response()->json(['ok' => true, 'url' => '/uploads/halls/' . $name]);
    }

    // ── Pricing ───────────────────────────────────────────────────────────

    public function pricing(): JsonResponse
    {
        $rules = PricingRule::all([
            'id', 'hall_id', 'booking_format', 'day_type', 'guest_tier',
            'min_hours', 'max_hours', 'price_per_hour', 'price_per_day',
            'prepayment_percent', 'description', 'is_active',
        ]);
        return response()->json($rules, 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules'                          => 'required|array',
            'rules.*.id'                     => 'required|integer|exists:pricing_rules,id',
            'rules.*.price_per_hour'         => 'nullable|integer|min:0',
            'rules.*.price_per_day'          => 'nullable|integer|min:0',
            'rules.*.prepayment_percent'     => 'nullable|integer|min:0|max:100',
            'rules.*.description'            => 'nullable|string|max:500',
            'rules.*.is_active'              => 'required|boolean',
        ]);

        foreach ($validated['rules'] as $r) {
            PricingRule::where('id', $r['id'])->update(array_filter([
                'price_per_hour'     => $r['price_per_hour']     ?? null,
                'price_per_day'      => $r['price_per_day']      ?? null,
                'prepayment_percent' => $r['prepayment_percent']  ?? null,
                'description'        => $r['description']         ?? null,
                'is_active'          => $r['is_active'],
            ], fn($v) => $v !== null));
        }

        // Регенерируем cms_data.js для затронутых залов
        $hallIds = PricingRule::whereIn('id', array_column($validated['rules'], 'id'))
            ->pluck('hall_id')->unique();

        $dir = rtrim(config('cms.public_dir', public_path()), '/');
        foreach ($hallIds as $hallId) {
            $hall = Hall::find($hallId);
            if (!$hall || !$hall->cms) continue;

            $cms = $hall->cms;

            // Синхронизируем cms.pricing.hourly из below30 hourly правил (цены на лендинге)
            if (!empty($cms['pricing']['hourly'])) {
                $below30 = PricingRule::where('hall_id', $hallId)
                    ->where('booking_format', 'hourly')
                    ->where('guest_tier', 'below30')
                    ->where('is_active', true)
                    ->get()->keyBy('day_type');

                $cms['pricing']['hourly'] = array_map(function ($row) use ($below30) {
                    if (empty($row['engine'])) return $row;
                    $dt   = $row['day_type'] ?? null;
                    $rule = $dt ? ($below30[$dt] ?? null) : null;
                    // Обновляем только строку с совпадающим description (не трогаем "Тренировки" и т.п.)
                    if ($rule && ($row['desc'] ?? '') === ($rule->description ?? '')) {
                        $row['price'] = $rule->price_per_hour;
                    }
                    return $row;
                }, $cms['pricing']['hourly']);

                $hall->update(['cms' => $cms]);
            }

            $allRules = PricingRule::where('hall_id', $hallId)->where('is_active', true)->get()
                ->map(fn($r) => [
                    'booking_format' => $r->booking_format,
                    'day_type'       => $r->day_type,
                    'guest_tier'     => $r->guest_tier,
                    'description'    => $r->description,
                    'price'          => $r->price_per_hour ?? $r->price_per_day,
                ]);

            $payload = array_merge($cms, ['pricing_rules' => $allRules->toArray()]);
            $js = 'window.HALL_CMS=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
            file_put_contents($dir . '/cms_data.js', $js);
        }

        return response()->json(['ok' => true]);
    }

    // ── Bookings ──────────────────────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with(['hall', 'client'])
            ->orderByDesc('date')
            ->orderByDesc('time_start');

        if ($request->query('date')) {
            $query->whereDate('date', $request->query('date'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $list = $query->limit(200)->get()->map(fn($b) => [
            'id'         => $b->id,
            'hall'       => $b->hall->name,
            'date'       => $b->date->format('Y-m-d'),
            'time_start' => substr($b->time_start, 0, 5),
            'time_end'   => substr($b->time_end,   0, 5),
            'format'     => $b->format,
            'status'     => $b->status,
            'total'      => $b->total_amount,
            'prepayment' => $b->prepayment_amount,
            'client'     => [
                'name'     => $b->client?->name,
                'phone'    => $b->client?->phone,
                'telegram' => $b->client?->telegram_username,
                'email'    => $b->client?->email,
            ],
        ]);

        return response()->json(['ok' => true, 'bookings' => $list], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hall_id'    => 'required|integer|exists:halls,id',
            'date'       => 'required|date',
            'time_start' => 'required|regex:/^\d{2}:\d{2}$/',
            'time_end'   => 'required|regex:/^\d{2}:\d{2}$/',
            'name'       => 'required|string|max:191',
            'phone'      => 'required|string|max:30',
            'email'      => 'nullable|email|max:191',
            'telegram'   => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:1000',
            'paid'       => 'boolean',
        ]);

        $data['consent_offer'] = true;
        $data['consent_policy'] = true;
        $data['consent_refund'] = true;

        $booking = $this->bookings->createHold($data);

        if (!empty($data['paid'])) {
            $booking->update(['status' => Booking::STATUS_PAID]);
        }

        return response()->json(['ok' => true, 'booking' => $booking->id], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function bookingDetail(int $id): JsonResponse
    {
        $b = Booking::with(['hall', 'client.user'])->findOrFail($id);
        $avatarUrl = $b->client?->avatar_url
            ?? $b->client?->user?->telegram_avatar_url;
        return response()->json(['ok' => true, 'booking' => [
            'id'                => $b->id,
            'hall'              => $b->hall?->name,
            'hall_id'           => $b->hall_id,
            'date'              => $b->date?->format('Y-m-d'),
            'time_start'        => substr($b->time_start, 0, 5),
            'time_end'          => substr($b->time_end,   0, 5),
            'duration_hours'    => $b->duration_hours,
            'guest_count'       => $b->guest_count,
            'format'            => $b->format,
            'status'            => $b->status,
            'total_amount'      => $b->total_amount,
            'prepayment_amount' => $b->prepayment_amount,
            'notes'             => $b->notes,
            'admin_notes'       => $b->admin_notes,
            'transaction_id'    => $b->transaction_id,
            'created_at'        => $b->created_at?->format('Y-m-d H:i'),
            'client_id'         => $b->client_id,
            'client_name'       => $b->client?->name,
            'client_phone'      => $b->client?->phone,
            'client_email'      => $b->client?->email,
            'client_telegram'   => $b->client?->telegram_username,
            'client_avatar_url' => $avatarUrl,
        ]], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function saveBookingAdminNote(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $booking->update(['admin_notes' => $request->input('admin_notes', '')]);
        return response()->json(['ok' => true]);
    }

    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $booking  = Booking::with(['client.user'])->findOrFail($id);
        $refund   = $this->bookings->cancel($booking);

        $chatId    = $booking->client->user->telegram_chat_id ?? null;
        $maxChatId = $booking->client->user->max_chat_id       ?? null;
        $this->notify->notifyClientCancelledDual($chatId, $maxChatId, $refund);

        return response()->json(['ok' => true]);
    }

    // ── Analytics ─────────────────────────────────────────────────────────

    public function analytics(Request $request): JsonResponse
    {
        $from = $request->query('from', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   Carbon::now()->toDateString());

        $bookings = Booking::whereBetween('date', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->get();

        $paid = $bookings->whereIn('status', ['paid', 'confirmed']);

        return response()->json([
            'ok'      => true,
            'period'  => compact('from', 'to'),
            'total'   => [
                'bookings'   => $bookings->count(),
                'revenue'    => $paid->sum('prepayment_amount'),
                'hours'      => $paid->sum('duration_hours'),
            ],
            'by_day'  => $paid->groupBy(fn($b) => $b->date->format('Y-m-d'))
                ->map(fn($g) => ['bookings' => $g->count(), 'revenue' => $g->sum('prepayment_amount'), 'hours' => $g->sum('duration_hours')])
                ->sortKeys(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Clients (owner only) ──────────────────────────────────────────────

    public function clients(Request $request): JsonResponse
    {
        if (!$request->user()->hasAnyRole(['owner', 'developer', 'admin'])) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $search      = $request->query('q');
        $blacklisted = $request->query('blacklisted');
        $limit       = (int) $request->query('limit', 0);

        $query = Client::with('user')->withCount('bookings')->orderByDesc('created_at');

        if ($blacklisted !== null) {
            $query->where('is_blacklisted', (bool) $blacklisted);
        }

        if ($search) {
            $q = '%' . $search . '%';
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', $q)
                  ->orWhere('phone', 'like', $q)
                  ->orWhere('telegram_username', 'like', $q)
                  ->orWhere('email', 'like', $q);
            });
        }

        $clients = ($limit > 0 ? $query->limit($limit) : $query)->get()->map(function ($c) {
            $lastBooking = $c->bookings()->orderByDesc('date')->value('date');
            return [
                'id'              => $c->id,
                'name'            => $c->name,
                'phone'           => $c->phone,
                'email'           => $c->email,
                'telegram'        => $c->telegram_username,
                'avatar_url'      => $c->avatar_url ?? $c->user?->telegram_avatar_url,
                'bookings'        => $c->bookings_count,
                'total_paid'      => $c->total_paid,
                'last_booking'    => $lastBooking,
                'is_blacklisted'  => $c->is_blacklisted,
                'admin_note'      => $c->admin_note ?: null,
                'created_at'      => $c->created_at->format('Y-m-d'),
                'role'            => $c->user?->getRoleNames()->first(),
            ];
        });

        return response()->json(['ok' => true, 'clients' => $clients], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function client(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c = Client::with(['user', 'bookings.hall'])->findOrFail($id);

        $bookings = $c->bookings->sortByDesc('date')->map(fn($b) => [
            'id'          => $b->id,
            'hall'        => $b->hall?->name ?? 'Зал',
            'date'        => $b->date,
            'time_start'  => substr($b->time_start, 0, 5),
            'time_end'    => substr($b->time_end,   0, 5),
            'format'      => $b->format,
            'status'      => $b->status,
            'total'       => $b->total_amount,
            'prepayment'  => $b->prepayment_amount,
        ])->values();

        return response()->json([
            'ok'     => true,
            'client' => [
                'id'             => $c->id,
                'name'           => $c->name,
                'phone'          => $c->phone,
                'email'          => $c->email,
                'telegram'       => $c->telegram_username,
                'telegram_id'    => $c->user?->telegram_chat_id,
                'avatar_url'     => $c->avatar_url ?? $c->user?->telegram_avatar_url,
                'admin_note'     => $c->admin_note,
                'is_blacklisted' => $c->is_blacklisted,
                'bookings_count' => $c->bookings_count,
                'total_paid'     => $c->total_paid,
                'created_at'     => $c->created_at->format('Y-m-d'),
                'role'           => $c->user?->getRoleNames()->first() ?? 'client',
            ],
            'bookings' => $bookings,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateClient(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c      = Client::findOrFail($id);
        $viewer = $request->user();

        if ($request->has('role') && $c->user) {
            $newRole = $request->input('role');
            $allowed = $viewer->hasRole('developer')
                ? ['developer', 'owner', 'admin', 'client']
                : ['admin', 'client'];

            if (!in_array($newRole, $allowed)) {
                return response()->json(['ok' => false, 'error' => 'Недостаточно прав для этой роли'], 403, [], JSON_UNESCAPED_UNICODE);
            }

            $c->user->syncRoles([$newRole]);
        }

        $c->fill($request->only(['name', 'phone', 'email', 'is_blacklisted', 'blacklist_reason']));
        $c->save();

        return response()->json(['ok' => true]);
    }

    public function updateClientNote(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c = Client::findOrFail($id);
        $c->admin_note = $request->input('note', '');
        $c->save();

        return response()->json(['ok' => true]);
    }

    public function deleteClient(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        Client::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Сброс пароля клиента — генерация нового + отправка в Telegram
     * POST /api/admin/clients/{id}/reset-password
     */
    public function resetClientPassword(Request $request, int $id): JsonResponse
    {
        $client = Client::with('user')->findOrFail($id);
        $user   = $client->user;

        if (!$user?->telegram_chat_id) {
            return response()->json([
                'ok'    => false,
                'error' => 'У клиента нет привязанного Telegram-аккаунта',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $notify = new NotificationService();
        $sent   = $notify->sendCredentials($user);

        if (!$sent) {
            return response()->json([
                'ok'    => false,
                'error' => 'Не удалось отправить сообщение в Telegram. Проверьте, написал ли клиент боту /start.',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['ok' => true, 'password' => $user->fresh()->client_password]);
    }

    public function debugFrontendReceive(Request $request): \Illuminate\Http\Response
    {
        $data = $request->getContent();
        if ($data) {
            $line = date('Y-m-d H:i:s') . ' ' . $data . "\n";
            file_put_contents(storage_path('logs/frontend.log'), $line, FILE_APPEND | LOCK_EX);
        }
        return response('', 204);
    }

    public function debugLog(Request $request): JsonResponse
    {
        if (!$this->isDeveloper($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $readLog = function (string $path, int $n = 100): array {
            if (!file_exists($path)) return [];
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_reverse(array_slice($lines, -$n));
        };

        $pricing = \App\Models\PricingRule::all([
            'id', 'booking_format', 'day_type', 'guest_tier',
            'min_hours', 'max_hours', 'price_per_hour', 'price_per_day',
            'prepayment_percent', 'is_active',
        ])->toArray();

        return response()->json([
            'ok'       => true,
            'pricing'  => $pricing,
            'frontend' => $readLog(storage_path('logs/frontend.log'), 150),
            'server'   => $readLog(storage_path('logs/laravel.log'),  80),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function clearDebugLog(Request $request): JsonResponse
    {
        if (!$this->isDeveloper($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $type = $request->query('type', 'all');
        $map  = [
            'server'   => [storage_path('logs/laravel.log')],
            'frontend' => [storage_path('logs/frontend.log')],
            'all'      => [storage_path('logs/laravel.log'), storage_path('logs/frontend.log')],
        ];
        foreach ($map[$type] ?? $map['all'] as $path) {
            if (file_exists($path)) {
                file_put_contents($path, '');
            }
        }

        return response()->json(['ok' => true]);
    }

    public function clientsCsv(Request $request): Response
    {
        if (!$this->isOwner($request)) {
            abort(403);
        }

        $clients = Client::withCount('bookings')->orderByDesc('created_at')->get();

        $esc = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';

        $rows = ["ID,Аватар,Имя,Телефон,Email,Telegram,Брони,Оплачено (руб),Первая бронь,Последняя бронь,Статус"];
        foreach ($clients as $c) {
            $firstBooking = $c->bookings()->orderBy('date')->value('date') ?? '';
            $lastBooking  = $c->bookings()->orderByDesc('date')->value('date') ?? '';
            $status       = $c->is_blacklisted ? 'В чёрном списке' : 'Активен';
            $rows[] = implode(',', [
                $c->id,
                $esc($c->avatar_url),
                $esc($c->name),
                $esc($c->phone),
                $esc($c->email),
                $esc($c->telegram_username),
                $c->bookings_count,
                $c->total_paid,
                $firstBooking,
                $lastBooking,
                $esc($status),
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\n", $rows);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="clients_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function analyticsCsv(Request $request): Response
    {
        if (!$this->isOwner($request)) abort(403);

        $bookings = Booking::with(['hall', 'client'])
            ->orderByDesc('date')->orderByDesc('time_start')
            ->get();

        $esc = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';

        $STATUS = ['pending'=>'Ожидание','confirmed'=>'Подтверждена','cancelled'=>'Отменена','completed'=>'Завершена','hold'=>'Холд'];
        $FORMAT = ['hourly'=>'Почасово','event'=>'Мероприятие'];

        $rows = [implode(',', ['Дата события','Время начала','Время конца','ID брони','Статус','Формат','Часов','Гостей','Сумма (руб)','ID транзакции','Зал','Имя клиента','Телефон','Email','Telegram','Дата создания заявки'])];

        foreach ($bookings as $b) {
            $ts = substr($b->time_start, 0, 5);
            $te = substr($b->time_end,   0, 5);

            if ($b->format === 'event') {
                $hours = 12;
            } else {
                [$sh, $sm] = array_map('intval', explode(':', $ts));
                [$eh, $em] = array_map('intval', explode(':', $te));
                $hours = round(($eh * 60 + $em - $sh * 60 - $sm) / 60, 1);
            }

            $rows[] = implode(',', [
                $b->date->format('d.m.Y'),
                $ts,
                $te,
                $b->id,
                $esc($STATUS[$b->status] ?? $b->status),
                $esc($FORMAT[$b->format] ?? $b->format),
                $hours,
                $b->guest_count ?? 0,
                $b->total_amount ?? 0,
                $esc($b->transaction_id),
                $esc($b->hall?->name),
                $esc($b->client?->name),
                $esc($b->client?->phone),
                $esc($b->client?->email),
                $esc($b->client?->telegram_username),
                $b->created_at->format('d.m.Y H:i'),
            ]);
        }

        return response("\xEF\xBB\xBF" . implode("\n", $rows), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="analytics_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function actionLogCsv(Request $request): Response
    {
        if (!$this->isDeveloper($request)) abort(403);

        $logs = DB::table('action_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select('al.id', 'al.user_id', 'al.role', 'al.action',
                     'al.target_type', 'al.target_id', 'al.payload', 'al.ip', 'al.created_at',
                     'u.name as user_name')
            ->orderByDesc('al.created_at')
            ->limit(10000)
            ->get();

        $esc = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';

        $rows = [implode(',', ['Время','Пользователь','Роль','Действие','Тип объекта','ID объекта','IP','Данные'])];
        foreach ($logs as $l) {
            $rows[] = implode(',', [
                $l->created_at,
                $esc($l->user_name),
                $esc($l->role),
                $esc($l->action),
                $esc($l->target_type),
                $l->target_id ?? '',
                $esc($l->ip),
                $esc($l->payload),
            ]);
        }

        return response("\xEF\xBB\xBF" . implode("\n", $rows), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="action_log_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    // ── All Bookings (full list with filters) ────────────────────────────

    public function allBookings(Request $request): JsonResponse
    {
        $query = Booking::with(['hall', 'client'])
            ->orderByDesc('date')
            ->orderByDesc('time_start');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('hall')) {
            $query->whereHas('hall', fn($q) => $q->where('name', $request->query('hall')));
        }
        if ($request->query('from')) {
            $query->whereDate('date', '>=', $request->query('from'));
        }
        if ($request->query('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        $list = $query->limit(500)->get()->map(fn($b) => [
            'id'                => $b->id,
            'hall'              => $b->hall?->name,
            'client_name'       => $b->client?->name,
            'client_phone'      => $b->client?->phone,
            'date'              => $b->date instanceof \Carbon\Carbon ? $b->date->format('Y-m-d') : $b->date,
            'time_start'        => substr($b->time_start, 0, 5),
            'time_end'          => substr($b->time_end, 0, 5),
            'format'            => $b->format,
            'status'            => $b->status,
            'total_amount'      => $b->total_amount,
            'prepayment_amount' => $b->prepayment_amount,
        ]);

        return response()->json(['ok' => true, 'data' => $list], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Heatmap ──────────────────────────────────────────────────────────

    public function heatmap(Request $request): JsonResponse
    {
        $query = Booking::with('hall')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereDate('date', '>=', now()->subMonths(3));

        if ($request->query('hall')) {
            $query->whereHas('hall', fn($q) => $q->where('name', $request->query('hall')));
        }

        $bookings = $query->get(['date', 'time_start', 'time_end']);
        $heatmap  = [];
        $max      = 0;

        foreach ($bookings as $b) {
            $dow = (int) Carbon::parse($b->date)->dayOfWeekIso - 1; // 0=Mon, 6=Sun
            [$hStart] = explode(':', $b->time_start);
            [$hEnd]   = explode(':', $b->time_end);
            for ($h = (int)$hStart; $h < (int)$hEnd; $h++) {
                $heatmap[$h][$dow] = ($heatmap[$h][$dow] ?? 0) + 1;
                if ($heatmap[$h][$dow] > $max) $max = $heatmap[$h][$dow];
            }
        }

        // Populate hall select options
        $halls = Hall::pluck('name');

        return response()->json(['ok' => true, 'heatmap' => $heatmap, 'max' => $max, 'halls' => $halls], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Action Log ───────────────────────────────────────────────────────

    public function actionLog(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 100), 500);

        $items = DB::table('action_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select('al.id', 'al.user_id', 'al.role', 'al.action',
                     'al.target_type', 'al.target_id',
                     'al.payload', 'al.ip', 'al.created_at',
                     'u.name as user_name')
            ->orderByDesc('al.created_at')
            ->limit($limit)
            ->get();

        $data = $items->map(function ($log) {
            $type = $log->target_type ?? '';
            $subject = match (true) {
                str_ends_with($type, 'Booking') => "Бронь #{$log->target_id}",
                str_ends_with($type, 'Client')  => "Клиент #{$log->target_id}",
                str_ends_with($type, 'Hall')    => "Зал #{$log->target_id}",
                $log->target_id !== null        => "#{$log->target_id}",
                default                         => null,
            };
            return [
                'id'        => $log->id,
                'user_id'   => $log->user_id,
                'user_name' => $log->user_name,
                'role'      => $log->role,
                'action'    => $log->action,
                'subject'   => $subject,
                'payload'   => $log->payload ? json_decode($log->payload, true) : null,
                'ip'        => $log->ip,
                'created_at'=> $log->created_at,
            ];
        });

        return response()->json(['ok' => true, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Users / Roles ────────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $users = User::with('roles')->orderBy('name')->get()->map(fn($u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'phone'          => $u->phone,
            'role'           => $u->getRoleNames()->first(),
            'is_blacklisted' => (bool) optional($u->client)->is_blacklisted,
            'is_active'      => (bool) ($u->is_active ?? true),
        ]);

        return response()->json(['ok' => true, 'data' => $users], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'is_blacklisted' => 'sometimes|boolean',
            'is_active'      => 'sometimes|boolean',
        ]);

        if (isset($data['is_blacklisted']) && $user->client) {
            $user->client->update(['is_blacklisted' => $data['is_blacklisted']]);
        }
        if (isset($data['is_active'])) {
            $user->update(['is_active' => $data['is_active']]);
        }

        return response()->json(['ok' => true]);
    }

    public function setUserRole(Request $request, int $id): JsonResponse
    {
        $viewer  = $request->user();
        $data    = $request->validate(['role' => 'required|string|in:developer,owner,admin,client']);
        $newRole = $data['role'];

        if ($viewer->hasRole('developer')) {
            // разраб может назначать любую роль
        } elseif ($viewer->hasRole('owner')) {
            if (!in_array($newRole, ['admin', 'client'])) {
                return response()->json(['ok' => false, 'error' => 'Недостаточно прав для этой роли'], 403, [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $user = User::findOrFail($id);
        $user->syncRoles([$newRole]);

        return response()->json(['ok' => true]);
    }
}
