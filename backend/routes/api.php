<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ── Telegram Bot ──────────────────────────────────────────────────────
Route::post('/telegram/webhook',      [TelegramController::class, 'webhook']);
Route::get('/telegram/set-webhook',   [TelegramController::class, 'setWebhook']);
Route::get('/telegram/webhook-info',  [TelegramController::class, 'webhookInfo']);
Route::get('/telegram/test',          [TelegramController::class, 'test']);
