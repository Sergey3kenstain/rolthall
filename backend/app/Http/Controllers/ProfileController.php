<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    /**
     * Вход по телефону + email — возвращает токен сессии (хэш phone+email)
     * POST /api/profile/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30',
            'email' => 'required|email|max:191',
        ]);

        $user = User::where('phone', $data['phone'])
            ->where('email', $data['email'])
            ->first();

        if (!$user) {
            return response()->json([
                'ok'    => false,
                'error' => 'Пользователь с таким телефоном и email не найден. Проверьте данные, которые указывали при бронировании.',
            ], 404);
        }

        // Проверка чёрного списка
        $client = $user->client;
        if ($client && $client->is_blacklisted) {
            return response()->json([
                'ok'    => false,
                'error' => 'Доступ ограничен. Обратитесь к администратору.',
            ], 403);
        }

        // Простой токен — хэш id+phone+email, достаточно для MVP
        $token = hash('sha256', $user->id . $user->phone . $user->email . config('app.key'));

        return response()->json([
            'ok'    => true,
            'token' => $token,
            'name'  => $user->name,
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
