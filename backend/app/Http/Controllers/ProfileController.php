<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    /**
     * Вход по телефону + паролю — возвращает токен сессии
     * POST /api/profile/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => 'required|string|max:30',
            'password' => 'required|string|max:64',
        ]);

        $last10 = substr(preg_replace('/[^\d]/', '', $data['phone']), -10);

        // Ищем пользователя по хвосту телефона
        $user = User::all()->first(
            fn($u) => str_ends_with(preg_replace('/[^\d]/', '', $u->phone ?? ''), $last10)
        );

        if (!$user) {
            return response()->json([
                'ok'    => false,
                'error' => 'Телефон или пароль неверны.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $client = $user->client;

        if (!$client || $client->client_password !== $data['password']) {
            return response()->json([
                'ok'    => false,
                'error' => 'Телефон или пароль неверны.',
            ], 401, [], JSON_UNESCAPED_UNICODE);
        }

        if ($client->is_blacklisted) {
            return response()->json([
                'ok'    => false,
                'error' => 'Доступ ограничен. Обратитесь к администратору.',
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $token = hash('sha256', $user->id . $user->phone . $user->email . config('app.key'));

        return response()->json([
            'ok'    => true,
            'token' => $token,
            'name'  => $user->name,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Клиент сбрасывает свой пароль — отправляем новые данные в Telegram
     * POST /api/profile/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['ok' => false, 'error' => 'Нет токена'], 401);
        }

        $user = User::all()->first(function ($u) use ($token) {
            $expected = hash('sha256', $u->id . $u->phone . $u->email . config('app.key'));
            return hash_equals($expected, $token);
        });

        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'Сессия истекла'], 401);
        }

        $client = $user->client;
        if (!$client) {
            return response()->json(['ok' => false, 'error' => 'Клиент не найден'], 404);
        }

        $chatId = $user->telegram_chat_id;
        if (!$chatId) {
            return response()->json([
                'ok'    => false,
                'error' => 'Telegram не привязан. Напишите боту /start чтобы получить новый пароль.',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $notify = new NotificationService();
        $sent   = $notify->sendCredentials($client, $chatId);

        if (!$sent) {
            return response()->json([
                'ok'    => false,
                'error' => 'Не удалось отправить сообщение в Telegram. Убедитесь, что вы написали боту /start.',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['ok' => true], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Автовход через Telegram WebApp initData
     * POST /api/profile/tg-login
     */
    public function tgLogin(Request $request): JsonResponse
    {
        $initData = $request->input('init_data', '');
        if (!$initData) {
            return response()->json(['ok' => false, 'error' => 'Нет init_data'], 400);
        }

        parse_str($initData, $params);
        $hash = $params['hash'] ?? '';
        unset($params['hash']);

        ksort($params);
        $dataCheckString = collect($params)
            ->map(fn($v, $k) => "{$k}={$v}")
            ->implode("\n");

        $secretKey    = hash_hmac('sha256', config('services.telegram.bot_token'), 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($expectedHash, $hash)) {
            return response()->json(['ok' => false, 'error' => 'Неверная подпись Telegram'], 403);
        }

        $tgUser = json_decode($params['user'] ?? '{}', true);
        $chatId = (string) ($tgUser['id'] ?? '');

        if (!$chatId) {
            return response()->json(['ok' => false, 'error' => 'Нет ID пользователя'], 400);
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $client = $user->client;
        if ($client && $client->is_blacklisted) {
            return response()->json(['ok' => false, 'error' => 'Доступ ограничен.'], 403);
        }

        $isStaff = $user->hasAnyRole(['owner', 'admin', 'manager', 'developer']);

        if ($isStaff) {
            $user->tokens()->where('name', 'staff')->delete();
            $token = $user->createToken('staff')->plainTextToken;
        } else {
            $token = hash('sha256', $user->id . $user->phone . $user->email . config('app.key'));
        }

        (new NotificationService())->refreshAvatar($user);

        return response()->json([
            'ok'         => true,
            'token'      => $token,
            'token_type' => $isStaff ? 'sanctum' : 'profile',
            'redirect_to' => $isStaff ? '/admin' : '/profile',
            'name'       => $user->name,
            'role'       => $user->getRoleNames()->first(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Брони клиента по токену
     * GET /api/profile/bookings?token=...
     */
    public function bookings(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json(['ok' => false, 'error' => 'Нет токена'], 401);
        }

        // Находим пользователя по токену
        $user = User::all()->first(function ($u) use ($token) {
            $expected = hash('sha256', $u->id . $u->phone . $u->email . config('app.key'));
            return hash_equals($expected, $token);
        });

        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'Сессия истекла'], 401);
        }

        $client = Client::where('user_id', $user->id)->first();
        if (!$client) {
            return response()->json(['ok' => true, 'bookings' => [], 'name' => $user->name]);
        }

        $bookings = Booking::where('client_id', $client->id)
            ->with('hall')
            ->orderByDesc('date')
            ->get()
            ->map(fn($b) => [
                'id'          => $b->id,
                'hall'        => $b->hall->name,
                'date'        => $b->date->format('Y-m-d'),
                'time_start'  => substr($b->time_start, 0, 5),
                'time_end'    => substr($b->time_end, 0, 5),
                'format'      => $b->format,
                'status'      => $b->status,
                'total'       => $b->total_amount,
                'prepayment'  => $b->prepayment_amount,
            ]);

        return response()->json([
            'ok'       => true,
            'name'     => $user->name,
            'user'     => [
                'phone'    => $user->phone,
                'email'    => $user->email,
                'telegram' => $user->telegram_username,
                'avatar'   => $user->telegram_avatar_url,
            ],
            'bookings' => $bookings,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Запрос переноса брони
     * POST /api/profile/reschedule-request
     */
    public function rescheduleRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'      => 'required|string',
            'booking_id' => 'required|integer',
            'new_date'   => 'required|date|after:today',
            'new_time'   => 'nullable|string|max:30',
            'comment'    => 'nullable|string|max:500',
        ]);

        $user = User::all()->first(function ($u) use ($data) {
            $expected = hash('sha256', $u->id . $u->phone . $u->email . config('app.key'));
            return hash_equals($expected, $data['token']);
        });

        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'Сессия истекла'], 401, [], JSON_UNESCAPED_UNICODE);
        }

        $client  = Client::where('user_id', $user->id)->first();
        $booking = Booking::with('hall')->find($data['booking_id']);

        if (!$booking || !$client || $booking->client_id !== $client->id) {
            return response()->json(['ok' => false, 'error' => 'Бронь не найдена'], 404, [], JSON_UNESCAPED_UNICODE);
        }

        // Telegram notification
        $file     = storage_path('app/telegram_settings.json');
        $settings = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $token    = $settings['token']     ?? config('services.telegram.bot_token');
        $chatId   = $settings['chat_id']   ?? config('services.telegram.admin_chat_id');
        $threadId = (int) ($settings['thread_id'] ?? config('services.telegram.admin_thread_id') ?? 0);

        $tplDefault = "📅 Запрос переноса брони #{booking_id}\n\n👤 Клиент: {name}\n📞 Телефон: {phone}\n💬 Телеграм: {telegram}\n\nТекущая дата: {old_date} ({old_time})\n🆕 Желаемая дата: {new_date}\n🕐 Желаемое время: {new_time}\n\n💬 Комментарий: {comment}";
        $tpl        = $settings['templates']['reschedule_request'] ?? $tplDefault;

        $vars = [
            '{booking_id}' => $booking->id,
            '{name}'       => $user->name,
            '{phone}'      => $user->phone,
            '{email}'      => $user->email,
            '{telegram}'   => $user->telegram_username ? '@'.$user->telegram_username : '—',
            '{old_date}'   => $booking->date->format('d.m.Y'),
            '{old_time}'   => substr($booking->time_start, 0, 5).'–'.substr($booking->time_end, 0, 5),
            '{new_date}'   => Carbon::parse($data['new_date'])->format('d.m.Y'),
            '{new_time}'   => $data['new_time'] ?? '—',
            '{comment}'    => $data['comment']  ?? '—',
            '{hall}'       => $booking->hall->name ?? 'RoltHall',
        ];

        $text = str_replace(array_keys($vars), array_values($vars), $tpl);

        try {
            $telegram = new \Telegram\Bot\Api($token);
            $params   = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
            if ($threadId) $params['message_thread_id'] = $threadId;
            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('Reschedule TG notification failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
