<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Регистрация сотрудника (скрытая страница, noindex)
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:191',
            'phone'    => 'required|string|max:30|unique:users,phone',
            'email'    => 'required|email|max:191|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'phone'    => $data['phone'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Аккаунт создан. Обратитесь к разработчику для назначения роли.',
        ], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Вход для персонала (email + password)
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['ok' => false, 'error' => 'Неверный email или пароль'], 401);
        }

        if (!$user->hasAnyRole(['owner', 'admin', 'manager', 'developer'])) {
            return response()->json(['ok' => false, 'error' => 'Доступ не назначен. Обратитесь к разработчику.'], 403);
        }

        $user->tokens()->where('name', 'staff')->delete();
        $token = $user->createToken('staff')->plainTextToken;

        return response()->json([
            'ok'    => true,
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->getRoleNames()->first(),
                'roles' => $user->getRoleNames()->values()->all(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Единый вход: телефон + email → определяет роль, выдаёт нужный токен
     * POST /api/auth/unified-login
     */
    public function unifiedLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30',
            'email' => 'required|email|max:191',
        ]);

        // Нормализуем телефон — убираем всё кроме цифр и ведущего +
        $phone = preg_replace('/[^\d+]/', '', $data['phone']);
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '+7' . substr($phone, 1);
        } elseif (strlen($phone) === 10) {
            $phone = '+7' . $phone;
        }

        $user = User::where('email', $data['email'])
            ->where('phone', $phone)
            ->first();

        if (!$user) {
            ActionLog::write('auth.login_failed', null, null, null, null,
                ['phone' => $data['phone'], 'email' => $data['email']], $request);
            return response()->json([
                'ok'    => false,
                'error' => 'Пользователь с таким телефоном и email не найден.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $role = $user->getRoleNames()->first() ?? 'client';
        $isStaff = $user->hasAnyRole(['owner', 'admin', 'manager', 'developer']);

        if ($isStaff) {
            // Выдаём Sanctum-токен для доступа к /admin/* API
            $user->tokens()->where('name', 'staff')->delete();
            $token = $user->createToken('staff')->plainTextToken;
        } else {
            // Клиентский SHA256-токен (без пароля, только идентификация)
            $client = $user->client ?? null;
            if ($client && $client->is_blacklisted) {
                ActionLog::write('auth.login_blocked', $user->id, $role, null, null, [], $request);
                return response()->json([
                    'ok'    => false,
                    'error' => 'Доступ ограничен. Обратитесь к администратору.',
                ], 403, [], JSON_UNESCAPED_UNICODE);
            }
            $token = hash('sha256', $user->id . $user->phone . $user->email . config('app.key'));
        }

        ActionLog::write('auth.login', $user->id, $role, null, null,
            ['name' => $user->name], $request);

        return response()->json([
            'ok'          => true,
            'token'       => $token,
            'token_type'  => $isStaff ? 'sanctum' : 'profile',
            'redirect_to' => $isStaff ? '/admin' : '/profile',
            'user'        => [
                'id'    => $user->id,
                'name'  => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role'  => $role,
                'roles' => $user->getRoleNames()->values()->all(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Выход
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('name', 'staff')->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Текущий пользователь
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'ok'    => true,
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role'  => $user->getRoleNames()->first(),
            'roles' => $user->getRoleNames()->values()->all(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
