<?php

use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ── Landing ───────────────────────────────────────────────────────────
Route::post('/landing/booking', [LandingController::class, 'booking']);

// ── Payment (T-Bank) ──────────────────────────────────────────────────
Route::post('/payment/init',           [PaymentController::class, 'init']);
Route::post('/payment/webhook',        [PaymentController::class, 'webhook']);
Route::get('/payment/status/{id}',     [PaymentController::class, 'status']);
Route::get('/payment/test',            [PaymentController::class, 'test']);

// ── Telegram Bot ──────────────────────────────────────────────────────
Route::post('/telegram/webhook',      [TelegramController::class, 'webhook']);
Route::get('/telegram/set-webhook',   [TelegramController::class, 'setWebhook']);
Route::get('/telegram/webhook-info',  [TelegramController::class, 'webhookInfo']);
Route::get('/telegram/test',          [TelegramController::class, 'test']);
