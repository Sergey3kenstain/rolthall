<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

        // Нормализуем телефон — последние 10 цифр (формат в БД может отличаться)
        $last10 = substr(preg_replace('/[^\d]/', '', $data['phone']), -10);

        $user = User::where('email', $data['email'])
            ->get()
            ->first(fn($u) => str_ends_with(preg_replace('/[^\d]/', '', $u->phone ?? ''), $last10));

        if (!$user) {
            ActionLog::write('auth.login_failed', null, null, null, null,
                ['phone' => $data['phone'], 'email' => $data['email']], $request);
            Log::warning('auth.login_failed', ['email' => $data['email'], 'phone_tail' => substr($last10, -4), 'ip' => $request->ip()]);
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

        if (!$isStaff && $user->telegram_chat_id) {
            (new NotificationService())->refreshAvatar($user);
        }

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
     * Обновление профиля текущего пользователя
     * PUT /api/auth/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => 'required|string|max:191',
            'phone' => 'required|string|max:30|unique:users,phone,' . $user->id,
            'email' => 'required|email|max:191|unique:users,email,' . $user->id,
        ]);

        $user->update($data);

        return response()->json(['ok' => true, 'name' => $user->name], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Текущий пользователь
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'ok'                  => true,
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'phone'               => $user->phone,
            'role'                => $user->getRoleNames()->first(),
            'roles'               => $user->getRoleNames()->values()->all(),
            'telegram_avatar_url' => $user->telegram_avatar_url,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Синхронизация данных Telegram WebApp
     * POST /api/auth/tg-sync
     *
     * Принимает initData из Telegram.WebApp.initData, верифицирует HMAC,
     * сохраняет telegram_chat_id, telegram_username, telegram_avatar_url.
     */
    public function tgSync(Request $request): JsonResponse
    {
        $initData = $request->input('init_data', '');
        if (!$initData) {
            return response()->json(['ok' => false, 'error' => 'No init_data'], 400);
        }

        // Верификация подписи Telegram
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
            return response()->json(['ok' => false, 'error' => 'Invalid signature'], 403);
        }

        // Парсим user из initData
        $tgUser = json_decode($params['user'] ?? '{}', true);
        $chatId   = (string) ($tgUser['id']         ?? '');
        $username = $tgUser['username']  ?? null;
        $photoUrl = $tgUser['photo_url'] ?? null;

        if (!$chatId) {
            return response()->json(['ok' => false, 'error' => 'No user id'], 400);
        }

        // Найти пользователя по chat_id или username
        $user = User::where('telegram_chat_id', $chatId)->first()
            ?? ($username ? User::where('telegram_username', $username)->first() : null);

        if ($user) {
            $updates = ['telegram_chat_id' => $chatId];
            if ($username) $updates['telegram_username'] = $username;
            if ($photoUrl && !$user->telegram_avatar_url) $updates['telegram_avatar_url'] = $photoUrl;
            $user->update($updates);
        }

        return response()->json([
            'ok'       => true,
            'linked'   => (bool) $user,
            'chat_id'  => $chatId,
            'username' => $username,
        ]);
    }

    /**
     * Сброс пароля staff-пользователя — генерация нового + отправка в Telegram
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->telegram_chat_id) {
            return response()->json([
                'ok'    => false,
                'error' => 'Telegram не привязан. Напишите боту /start, затем повторите.',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $sent = (new NotificationService())->sendCredentials($user);

        if (!$sent) {
            return response()->json([
                'ok'    => false,
                'error' => 'Не удалось отправить сообщение в Telegram.',
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['ok' => true], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Получаем сохранённые данные пользователя по Telegram chat_id
     * GET /api/auth/tg-profile?tg_id=123456789
     *
     * Используется для предзаполнения формы бронирования если пользователь
     * ранее бронировал или отправил контакт боту.
     */
    public function tgProfile(Request $request): JsonResponse
    {
        $tgId = $request->query('tg_id');
        if (!$tgId) {
            return response()->json(['ok' => false], 400);
        }

        $user = User::where('telegram_chat_id', (string) $tgId)->first();

        if (!$user) {
            return response()->json(['ok' => false, 'found' => false]);
        }

        return response()->json([
            'ok'       => true,
            'found'    => true,
            'name'     => $user->name  ?? '',
            'phone'    => $user->phone ?? '',
            'email'    => $user->email ?? '',
            'username' => $user->telegram_username ?? '',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
