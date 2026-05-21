<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ── Halls ─────────────────────────────────────────────────────────────
Route::get('/halls',                        [HallController::class, 'index']);
Route::get('/halls/{hall}',                 [HallController::class, 'show']);
Route::get('/halls/{hall}/pricing',         [HallController::class, 'pricing']);
Route::get('/halls/{hall}/availability',    [HallController::class, 'availability']);

// ── Landing ───────────────────────────────────────────────────────────
Route::post('/landing/booking', [LandingController::class, 'booking']);

// ── Bookings ──────────────────────────────────────────────────────────
Route::post('/bookings/hold',          [BookingController::class, 'hold']);
Route::get('/bookings/{booking}/status',[BookingController::class, 'status']);
Route::post('/bookings/{booking}/cancel',[BookingController::class, 'cancel']);

// ── Payment (T-Bank) ──────────────────────────────────────────────────
Route::post('/payment/init',           [PaymentController::class, 'init']);
Route::post('/payment/webhook',        [PaymentController::class, 'webhook']);
Route::get('/payment/status/{id}',     [PaymentController::class, 'status']);
Route::get('/payment/test',            [PaymentController::class, 'test']);
Route::get('/payment/debug',           [PaymentController::class, 'debug']);

// ── Telegram Bot ──────────────────────────────────────────────────────
Route::post('/telegram/webhook',      [TelegramController::class, 'webhook']);
Route::get('/telegram/set-webhook',   [TelegramController::class, 'setWebhook']);
Route::get('/telegram/webhook-info',  [TelegramController::class, 'webhookInfo']);
Route::get('/telegram/test',          [TelegramController::class, 'test']);
