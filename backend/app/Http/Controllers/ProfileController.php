<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
