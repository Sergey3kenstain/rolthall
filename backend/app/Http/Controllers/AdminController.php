<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Hall;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\BookingService;
use App\Services\TBankService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private TBankService   $tbank,
    ) {}

    private function isOwner(Request $r): bool
    {
        return $r->user()->hasAnyRole(['owner', 'developer']);
    }

    // ── Hall ──────────────────────────────────────────────────────────────

    public function halls(?int $id = null): JsonResponse
    {
        if ($id) {
            $hall = Hall::with('pricingRules')->findOrFail($id);
            return response()->json($hall, 200, [], JSON_UNESCAPED_UNICODE);
        }
        return response()->json(Hall::with('pricingRules')->get(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateHall(Request $request, int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:191',
            'description'    => 'sometimes|string',
            'area_m2'        => 'sometimes|numeric',
            'capacity'       => 'sometimes|integer',
            'buffer_minutes' => 'sometimes|integer',
            'rules'          => 'sometimes|string',
            'contact_phone'  => 'sometimes|string|max:30|nullable',
        ]);
        $hall->update($data);
        return response()->json(['ok' => true, 'hall' => $hall], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Pricing ───────────────────────────────────────────────────────────

    public function pricing(): JsonResponse
    {
        return response()->json(PricingRule::all(['id', 'hall_id', 'day_type', 'min_hours', 'max_hours', 'price_per_hour', 'is_active']), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $rules = $request->validate([
            'rules'                    => 'required|array',
            'rules.*.id'               => 'required|integer|exists:pricing_rules,id',
            'rules.*.price_per_hour'   => 'required|integer|min:0',
            'rules.*.is_active'        => 'required|boolean',
        ]);
        foreach ($rules['rules'] as $r) {
            PricingRule::where('id', $r['id'])->update([
                'price_per_hour' => $r['price_per_hour'],
                'is_active'      => $r['is_active'],
            ]);
        }
        return response()->json(['ok' => true]);
    }

    // ── Bookings ──────────────────────────────────────────────────────────

    public function bookings(Request $request): JsonResponse
    {
        $query = Booking::with(['hall', 'client'])
            ->orderByDesc('date')
            ->orderByDesc('time_start');

        if ($request->query('date')) {
            $query->whereDate('date', $request->query('date'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $list = $query->limit(200)->get()->map(fn($b) => [
            'id'         => $b->id,
            'hall'       => $b->hall->name,
            'date'       => $b->date->format('Y-m-d'),
            'time_start' => substr($b->time_start, 0, 5),
            'time_end'   => substr($b->time_end,   0, 5),
            'format'     => $b->format,
            'status'     => $b->status,
            'total'      => $b->total_amount,
            'prepayment' => $b->prepayment_amount,
            'client'     => [
                'name'     => $b->client?->name,
                'phone'    => $b->client?->phone,
                'telegram' => $b->client?->telegram_username,
                'email'    => $b->client?->email,
            ],
        ]);

        return response()->json(['ok' => true, 'bookings' => $list], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hall_id'    => 'required|integer|exists:halls,id',
            'date'       => 'required|date',
            'time_start' => 'required|regex:/^\d{2}:\d{2}$/',
            'time_end'   => 'required|regex:/^\d{2}:\d{2}$/',
            'name'       => 'required|string|max:191',
            'phone'      => 'required|string|max:30',
            'email'      => 'nullable|email|max:191',
            'telegram'   => 'nullable|string|max:100',
            'notes'      => 'nullable|string|max:1000',
            'paid'       => 'boolean',
        ]);

        $data['consent_offer'] = true;
        $data['consent_policy'] = true;
        $data['consent_refund'] = true;

        $booking = $this->bookings->createHold($data);

        if (!empty($data['paid'])) {
            $booking->update(['status' => Booking::STATUS_PAID]);
        }

        return response()->json(['ok' => true, 'booking' => $booking->id], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);
        $this->bookings->cancel($booking);
        return response()->json(['ok' => true]);
    }

    // ── Analytics ─────────────────────────────────────────────────────────

    public function analytics(Request $request): JsonResponse
    {
        $from = $request->query('from', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   Carbon::now()->toDateString());

        $bookings = Booking::whereBetween('date', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->get();

        $paid = $bookings->whereIn('status', ['paid', 'confirmed']);

        return response()->json([
            'ok'      => true,
            'period'  => compact('from', 'to'),
            'total'   => [
                'bookings'   => $bookings->count(),
                'revenue'    => $paid->sum('prepayment_amount'),
                'hours'      => $paid->sum('duration_hours'),
            ],
            'by_day'  => $paid->groupBy(fn($b) => $b->date->format('Y-m-d'))
                ->map(fn($g) => ['bookings' => $g->count(), 'revenue' => $g->sum('prepayment_amount'), 'hours' => $g->sum('duration_hours')])
                ->sortKeys(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Clients (owner only) ──────────────────────────────────────────────

    public function clients(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $clients = Client::with('user')
            ->withCount('bookings')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'name'        => $c->name,
                'phone'       => $c->phone,
                'email'       => $c->email,
                'telegram'    => $c->telegram_username,
                'bookings'    => $c->bookings_count,
                'total_paid'  => $c->total_paid,
                'created_at'  => $c->created_at->format('Y-m-d'),
            ]);

        return response()->json(['ok' => true, 'clients' => $clients], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function debugLog(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $logPath = storage_path('logs/laravel.log');
        if (!file_exists($logPath)) {
            return response()->json(['ok' => true, 'lines' => []]);
        }

        $lines   = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last100 = array_slice($lines, -100);

        return response()->json([
            'ok'    => true,
            'lines' => array_reverse($last100),
            'total' => count($lines),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function clientsCsv(Request $request): Response
    {
        if (!$this->isOwner($request)) {
            abort(403);
        }

        $clients = Client::with('user')->withCount('bookings')->orderByDesc('created_at')->get();

        $rows = ["Имя,Телефон,Email,Telegram,Брони,Оплачено,Дата регистрации"];
        foreach ($clients as $c) {
            $rows[] = implode(',', [
                '"' . str_replace('"', '""', $c->name)  . '"',
                $c->phone,
                $c->email,
                $c->telegram_username ?? '',
                $c->bookings_count,
                $c->total_paid,
                $c->created_at->format('Y-m-d'),
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\n", $rows); // UTF-8 BOM для Excel

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="clients_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
