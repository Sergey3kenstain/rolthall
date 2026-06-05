<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\EventAdminController;
use App\Http\Controllers\EventPublicController;
use App\Http\Controllers\HallController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TelegramController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/unified-login', [AuthController::class, 'unifiedLogin']);
    Route::post('/auth/register',      [AuthController::class, 'register']);
    Route::post('/auth/login',         [AuthController::class, 'login']);
});
Route::post('/auth/tg-sync',    [AuthController::class, 'tgSync'])->middleware('throttle:30,1');
Route::get('/auth/tg-profile',  [AuthController::class, 'tgProfile'])->middleware('throttle:30,1');
Route::get('/auth/max-profile', [AuthController::class, 'maxProfile'])->middleware('throttle:30,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',         [AuthController::class, 'logout']);
    Route::get('/auth/me',              [AuthController::class, 'me']);
    Route::put('/auth/profile',         [AuthController::class, 'updateProfile']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});

// ── Admin (owner + manager) ───────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/halls',                    [AdminController::class, 'halls']);
    Route::post('/halls',                   [AdminController::class, 'createHall']);
    Route::get('/halls/{id}',               [AdminController::class, 'halls']);
    Route::put('/halls/{id}',               [AdminController::class, 'updateHall']);
    Route::delete('/halls/{id}',            [AdminController::class, 'deleteHall']);
    Route::post('/halls/{id}/publish',      [AdminController::class, 'publishHall']);
    Route::post('/upload',                  [AdminController::class, 'upload']);
    Route::get('/pricing',                  [AdminController::class, 'pricing']);
    Route::put('/pricing',                  [AdminController::class, 'updatePricing']);
    Route::get('/bookings',                      [AdminController::class, 'bookings']);
    Route::get('/bookings/all',                  [AdminController::class, 'allBookings']);
    Route::get('/bookings/{id}',                 [AdminController::class, 'bookingDetail']);
    Route::post('/bookings',                     [AdminController::class, 'createBooking']);
    Route::post('/bookings/{id}/cancel',         [AdminController::class, 'cancelBooking']);
    Route::put('/bookings/{id}/admin-note',      [AdminController::class, 'saveBookingAdminNote']);
    Route::get('/analytics',                [AdminController::class, 'analytics']);
    Route::get('/analytics/csv',            [AdminController::class, 'analyticsCsv']);
    Route::get('/heatmap',                  [AdminController::class, 'heatmap']);
    Route::get('/log',                      [AdminController::class, 'actionLog']);
    Route::get('/log/csv',                  [AdminController::class, 'actionLogCsv']);
    Route::get('/clients',                  [AdminController::class, 'clients']);
    Route::get('/clients/csv',              [AdminController::class, 'clientsCsv']);
    Route::get('/clients/{id}',             [AdminController::class, 'client']);
    Route::put('/clients/{id}',             [AdminController::class, 'updateClient']);
    Route::delete('/clients/{id}',          [AdminController::class, 'deleteClient']);
    Route::post('/clients/{id}/note',           [AdminController::class, 'updateClientNote']);
    Route::post('/clients/{id}/reset-password', [AdminController::class, 'resetClientPassword']);
    Route::get('/users',                    [AdminController::class, 'users']);
    Route::put('/users/{id}',               [AdminController::class, 'updateUser']);
    Route::put('/users/{id}/role',          [AdminController::class, 'setUserRole']);
    Route::get('/debug/log',                [AdminController::class, 'debugLog']);
    Route::delete('/debug/log',             [AdminController::class, 'clearDebugLog']);
    Route::get('/telegram/settings',        [AdminController::class, 'telegramSettings']);
    Route::post('/telegram/settings',       [AdminController::class, 'saveTelegramSettings']);
    Route::post('/telegram/test-template',  [AdminController::class, 'testTemplate']);
    Route::get('/max/settings',             [AdminController::class, 'maxSettings']);
    Route::post('/max/settings',            [AdminController::class, 'saveMaxSettings']);
    Route::get('/max/test-send',            [AdminController::class, 'testMaxSend']);
    Route::post('/max/set-commands',        [AdminController::class, 'setMaxCommands']);
    Route::post('/max/test-template',       [AdminController::class, 'testMaxTemplate']);
});

// ── Halls ─────────────────────────────────────────────────────────────
Route::get('/halls',                        [HallController::class, 'index']);
Route::get('/halls/{hall}',                 [HallController::class, 'show']);
Route::get('/halls/{hall}/pricing',         [HallController::class, 'pricing']);
Route::get('/halls/{hall}/availability',    [HallController::class, 'availability']);

// ── Frontend error collector (публичный приём, чтение — owner only) ──
Route::post('/debug/frontend',  [AdminController::class, 'debugFrontendReceive']);

// ── Profile ───────────────────────────────────────────────────────────
Route::post('/profile/login',    [ProfileController::class, 'login'])->middleware('throttle:10,1');
Route::post('/profile/tg-login', [ProfileController::class, 'tgLogin'])->middleware('throttle:20,1');
Route::get('/profile/bookings',             [ProfileController::class, 'bookings']);
Route::post('/profile/reschedule-request',  [ProfileController::class, 'rescheduleRequest']);
Route::post('/profile/reset-password',      [ProfileController::class, 'resetPassword'])->middleware('throttle:5,1');

// ── Public config ─────────────────────────────────────────────────────
Route::get('/config/bots', function () {
    $tg  = file_exists(storage_path('app/telegram_settings.json'))
        ? (json_decode(file_get_contents(storage_path('app/telegram_settings.json')), true) ?? [])
        : [];
    $max = file_exists(storage_path('app/max_settings.json'))
        ? (json_decode(file_get_contents(storage_path('app/max_settings.json')), true) ?? [])
        : [];
    return response()->json([
        'ok'  => true,
        'tg'  => ['link' => $tg['bot_link']  ?? 'https://t.me/rolthall_bot'],
        'max' => ['link' => $max['bot_link'] ?? null],
    ]);
})->middleware('throttle:60,1');

// ── Landing ───────────────────────────────────────────────────────────
Route::post('/landing/booking', [LandingController::class, 'booking'])->middleware('throttle:10,1');

// ── Bookings ──────────────────────────────────────────────────────────
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/bookings/hold',       [BookingController::class, 'hold']);
    Route::post('/bookings/event-hold', [BookingController::class, 'eventHold']);
    Route::post('/bookings/inquiry',    [BookingController::class, 'inquiry']);
});
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

// ── Events (admin) ────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('admin/events')->group(function () {
    Route::get('/',                              [EventAdminController::class, 'index']);
    Route::post('/',                             [EventAdminController::class, 'store']);
    Route::get('/{id}',                          [EventAdminController::class, 'show']);
    Route::put('/{id}',                          [EventAdminController::class, 'update']);
    Route::delete('/{id}',                       [EventAdminController::class, 'destroy']);
    Route::post('/{id}/fields',                  [EventAdminController::class, 'syncFields']);
    Route::post('/{id}/tariffs',                 [EventAdminController::class, 'syncTariffs']);
    Route::post('/{id}/poster',                  [EventAdminController::class, 'uploadPoster']);
    Route::delete('/{id}/poster',               [EventAdminController::class, 'deletePoster']);
    Route::post('/{id}/ticket-template',         [EventAdminController::class, 'uploadTicketTemplate']);
    Route::delete('/{id}/ticket-template',       [EventAdminController::class, 'deleteTicketTemplate']);
    Route::post('/{id}/ticket-preview',          [EventAdminController::class, 'ticketPreview']);
    Route::get('/{id}/registrations',             [EventAdminController::class, 'registrations']);
    Route::get('/{id}/registrations/{regId}',     [EventAdminController::class, 'registrationDetail']);
    Route::delete('/{id}/registrations/{regId}',  [EventAdminController::class, 'deleteRegistration']);
});

// ── Events (public form) ──────────────────────────────────────────────
Route::get('/events/{slug}',                     [EventPublicController::class, 'show'])->middleware('throttle:60,1');
Route::post('/events/{slug}/register',           [EventPublicController::class, 'register'])->middleware('throttle:10,1');
Route::get('/events/{slug}/ticket/{regId}',      [EventPublicController::class, 'ticketImage'])->middleware('throttle:30,1');
Route::post('/events/payment-webhook',           [EventPublicController::class, 'paymentWebhook']);

// ── Max Bot ───────────────────────────────────────────────────────────
Route::post('/max/webhook',           [MaxController::class, 'webhook']);
Route::get('/max/set-webhook',        [MaxController::class, 'setWebhook']);
Route::get('/max/webhook-info',       [MaxController::class, 'webhookInfo']);
Route::get('/max/test',               [MaxController::class, 'test']);
Route::get('/max/me',                 [MaxController::class, 'me']);
