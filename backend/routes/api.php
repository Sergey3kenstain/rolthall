<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────
Route::post('/auth/unified-login', [AuthController::class, 'unifiedLogin']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
});

// ── Admin (owner + manager) ───────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/halls',                    [AdminController::class, 'halls']);
    Route::post('/halls',                   [AdminController::class, 'createHall']);
    Route::get('/halls/{id}',               [AdminController::class, 'halls']);
    Route::put('/halls/{id}',               [AdminController::class, 'updateHall']);
    Route::delete('/halls/{id}',            [AdminController::class, 'deleteHall']);
    Route::get('/pricing',                  [AdminController::class, 'pricing']);
    Route::put('/pricing',                  [AdminController::class, 'updatePricing']);
    Route::get('/bookings',                 [AdminController::class, 'bookings']);
    Route::get('/bookings/all',             [AdminController::class, 'allBookings']);
    Route::post('/bookings',                [AdminController::class, 'createBooking']);
    Route::post('/bookings/{id}/cancel',    [AdminController::class, 'cancelBooking']);
    Route::get('/analytics',                [AdminController::class, 'analytics']);
    Route::get('/heatmap',                  [AdminController::class, 'heatmap']);
    Route::get('/log',                      [AdminController::class, 'actionLog']);
    Route::get('/clients',                  [AdminController::class, 'clients']);
    Route::get('/clients/csv',              [AdminController::class, 'clientsCsv']);
    Route::get('/clients/{id}',             [AdminController::class, 'client']);
    Route::put('/clients/{id}',             [AdminController::class, 'updateClient']);
    Route::delete('/clients/{id}',          [AdminController::class, 'deleteClient']);
    Route::post('/clients/{id}/note',       [AdminController::class, 'updateClientNote']);
    Route::get('/users',                    [AdminController::class, 'users']);
    Route::put('/users/{id}',               [AdminController::class, 'updateUser']);
    Route::put('/users/{id}/role',          [AdminController::class, 'setUserRole']);
    Route::get('/debug/log',                [AdminController::class, 'debugLog']);
});

// ── Halls ─────────────────────────────────────────────────────────────
Route::get('/halls',                        [HallController::class, 'index']);
Route::get('/halls/{hall}',                 [HallController::class, 'show']);
Route::get('/halls/{hall}/pricing',         [HallController::class, 'pricing']);
Route::get('/halls/{hall}/availability',    [HallController::class, 'availability']);

// ── Frontend error collector (публичный приём, чтение — owner only) ──
Route::post('/debug/frontend',  [AdminController::class, 'debugFrontendReceive']);

// ── Profile ───────────────────────────────────────────────────────────
Route::post('/profile/login',    [ProfileController::class, 'login']);
Route::get('/profile/bookings',  [ProfileController::class, 'bookings']);

// ── Landing ───────────────────────────────────────────────────────────
Route::post('/landing/booking', [LandingController::class, 'booking']);

// ── Bookings ──────────────────────────────────────────────────────────
Route::post('/bookings/hold',           [BookingController::class, 'hold']);
Route::post('/bookings/event-hold',     [BookingController::class, 'eventHold']);
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
