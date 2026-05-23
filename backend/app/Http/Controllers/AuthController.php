<?php

namespace App\Http\Controllers;

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
     * Вход для персонала
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

        if (!$user->hasAnyRole(['owner', 'manager', 'developer'])) {
            return response()->json(['ok' => false, 'error' => 'Доступ не назначен. Обратитесь к разработчику.'], 403);
        }

        // Удаляем старые токены персонала
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
            'role'  => $user->getRoleNames()->first(),
            'roles' => $user->getRoleNames()->values()->all(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
