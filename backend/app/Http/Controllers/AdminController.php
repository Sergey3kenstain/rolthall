<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
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
use Illuminate\Support\Facades\DB;

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

    public function createHall(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) abort(403);

        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'description'    => 'nullable|string',
            'area_m2'        => 'nullable|integer',
            'capacity'       => 'nullable|integer',
            'equipment'      => 'nullable|array',
            'equipment.*'    => 'string|max:100',
            'buffer_minutes' => 'nullable|integer',
            'rules'          => 'nullable|string',
            'contact_phone'  => 'nullable|string|max:30',
            'is_active'      => 'boolean',
        ]);

        $hall = Hall::create(array_merge(['is_active' => true], $data));
        return response()->json(['ok' => true, 'hall' => $hall->load('pricingRules')], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateHall(Request $request, int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:191',
            'description'    => 'sometimes|nullable|string',
            'area_m2'        => 'sometimes|nullable|integer',
            'capacity'       => 'sometimes|nullable|integer',
            'equipment'      => 'sometimes|nullable|array',
            'equipment.*'    => 'string|max:100',
            'buffer_minutes' => 'sometimes|nullable|integer',
            'rules'          => 'sometimes|nullable|string',
            'contact_phone'  => 'sometimes|nullable|string|max:30',
            'is_active'      => 'sometimes|boolean',
        ]);
        $hall->update($data);
        return response()->json(['ok' => true, 'hall' => $hall->fresh('pricingRules')], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function deleteHall(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) abort(403);
        Hall::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    // ── Hall CMS publish ─────────────────────────────────────────────────

    public function publishHall(Request $request, int $id): JsonResponse
    {
        $hall = Hall::findOrFail($id);
        $cms  = $request->validate(['cms' => 'required|array'])['cms'];

        // 1. Сохраняем CMS в БД
        $hall->update(['cms' => $cms]);

        // 2. Синхронизируем pricing_rules из cms.pricing.hourly (первые 2 строки — training)
        $hourly = $cms['pricing']['hourly'] ?? [];
        foreach ($hourly as $row) {
            if (empty($row['engine'])) continue; // только строки с engine:true
            PricingRule::updateOrCreate(
                ['hall_id' => $hall->id, 'day_type' => $row['day_type']],
                ['price_per_hour' => (int) $row['price'], 'description' => $row['desc'] ?? null, 'is_active' => true, 'min_hours' => 1]
            );
        }

        // 3. Генерируем cms_data.js
        $rules = PricingRule::where('hall_id', $hall->id)->where('is_active', true)->get()
            ->map(fn($r) => ['day_type' => $r->day_type, 'description' => $r->description, 'price' => $r->price_per_hour]);

        $payload = array_merge($cms, ['pricing_rules' => $rules]);
        $js = 'window.HALL_CMS=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        $dir = config('cms.public_dir', public_path());
        file_put_contents(rtrim($dir, '/') . '/cms_data.js', $js);

        return response()->json(['ok' => true]);
    }

    // ── File upload ───────────────────────────────────────────────────────

    public function upload(Request $request): JsonResponse
    {
        $type    = $request->input('type', 'carousel'); // 'hero' | 'carousel'
        $maxKb   = $type === 'hero' ? 1536 : 1024;      // 1.5 MB / 1 MB
        $request->validate(['file' => "required|file|image|max:{$maxKb}"]);

        $file = $request->file('file');
        $dir  = public_path('uploads/halls');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $name = uniqid($type.'_', true) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $name);

        return response()->json(['ok' => true, 'url' => '/uploads/halls/' . $name]);
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

        $search      = $request->query('q');
        $blacklisted = $request->query('blacklisted');
        $limit       = (int) $request->query('limit', 0);

        $query = Client::with('user')->withCount('bookings')->orderByDesc('created_at');

        if ($blacklisted !== null) {
            $query->where('is_blacklisted', (bool) $blacklisted);
        }

        if ($search) {
            $q = '%' . $search . '%';
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', $q)
                  ->orWhere('phone', 'like', $q)
                  ->orWhere('telegram_username', 'like', $q)
                  ->orWhere('email', 'like', $q);
            });
        }

        $clients = ($limit > 0 ? $query->limit($limit) : $query)->get()->map(function ($c) {
            $lastBooking = $c->bookings()->orderByDesc('date')->value('date');
            return [
                'id'              => $c->id,
                'name'            => $c->name,
                'phone'           => $c->phone,
                'email'           => $c->email,
                'telegram'        => $c->telegram_username,
                'avatar_url'      => $c->avatar_url,
                'bookings'        => $c->bookings_count,
                'total_paid'      => $c->total_paid,
                'last_booking'    => $lastBooking,
                'is_blacklisted'  => $c->is_blacklisted,
                'created_at'      => $c->created_at->format('Y-m-d'),
            ];
        });

        return response()->json(['ok' => true, 'clients' => $clients], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function client(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c = Client::with(['user', 'bookings.hall'])->findOrFail($id);

        $bookings = $c->bookings->sortByDesc('date')->map(fn($b) => [
            'id'          => $b->id,
            'hall'        => $b->hall?->name ?? 'Зал',
            'date'        => $b->date,
            'time_start'  => substr($b->time_start, 0, 5),
            'time_end'    => substr($b->time_end,   0, 5),
            'format'      => $b->format,
            'status'      => $b->status,
            'total'       => $b->total_amount,
            'prepayment'  => $b->prepayment_amount,
        ])->values();

        return response()->json([
            'ok'     => true,
            'client' => [
                'id'             => $c->id,
                'name'           => $c->name,
                'phone'          => $c->phone,
                'email'          => $c->email,
                'telegram'       => $c->telegram_username,
                'avatar_url'     => $c->avatar_url,
                'admin_note'     => $c->admin_note,
                'is_blacklisted' => $c->is_blacklisted,
                'bookings_count' => $c->bookings_count,
                'total_paid'     => $c->total_paid,
                'created_at'     => $c->created_at->format('Y-m-d'),
            ],
            'bookings' => $bookings,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateClient(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c = Client::findOrFail($id);
        $c->fill($request->only(['name', 'phone', 'email', 'telegram_username', 'is_blacklisted', 'blacklist_reason']));
        $c->save();

        return response()->json(['ok' => true]);
    }

    public function updateClientNote(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $c = Client::findOrFail($id);
        $c->admin_note = $request->input('note', '');
        $c->save();

        return response()->json(['ok' => true]);
    }

    public function deleteClient(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        Client::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function debugFrontendReceive(Request $request): \Illuminate\Http\Response
    {
        $data = $request->getContent();
        if ($data) {
            $line = date('Y-m-d H:i:s') . ' ' . $data . "\n";
            file_put_contents(storage_path('logs/frontend.log'), $line, FILE_APPEND | LOCK_EX);
        }
        return response('', 204);
    }

    public function debugLog(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }

        $readLog = function (string $path, int $n = 100): array {
            if (!file_exists($path)) return [];
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_reverse(array_slice($lines, -$n));
        };

        return response()->json([
            'ok'       => true,
            'frontend' => $readLog(storage_path('logs/frontend.log'), 100),
            'server'   => $readLog(storage_path('logs/laravel.log'),  50),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function clientsCsv(Request $request): Response
    {
        if (!$this->isOwner($request)) {
            abort(403);
        }

        $clients = Client::withCount('bookings')->orderByDesc('created_at')->get();

        $esc = fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"';

        $rows = ["ID,Аватар,Имя,Телефон,Email,Telegram,Брони,Оплачено (руб),Первая бронь,Последняя бронь,Статус"];
        foreach ($clients as $c) {
            $firstBooking = $c->bookings()->orderBy('date')->value('date') ?? '';
            $lastBooking  = $c->bookings()->orderByDesc('date')->value('date') ?? '';
            $status       = $c->is_blacklisted ? 'В чёрном списке' : 'Активен';
            $rows[] = implode(',', [
                $c->id,
                $esc($c->avatar_url),
                $esc($c->name),
                $esc($c->phone),
                $esc($c->email),
                $esc($c->telegram_username),
                $c->bookings_count,
                $c->total_paid,
                $firstBooking,
                $lastBooking,
                $esc($status),
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\n", $rows);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="clients_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    // ── All Bookings (full list with filters) ────────────────────────────

    public function allBookings(Request $request): JsonResponse
    {
        $query = Booking::with(['hall', 'client'])
            ->orderByDesc('date')
            ->orderByDesc('time_start');

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('hall')) {
            $query->whereHas('hall', fn($q) => $q->where('name', $request->query('hall')));
        }
        if ($request->query('from')) {
            $query->whereDate('date', '>=', $request->query('from'));
        }
        if ($request->query('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        $list = $query->limit(500)->get()->map(fn($b) => [
            'id'                => $b->id,
            'hall'              => $b->hall?->name,
            'client_name'       => $b->client?->name,
            'client_phone'      => $b->client?->phone,
            'date'              => $b->date instanceof \Carbon\Carbon ? $b->date->format('Y-m-d') : $b->date,
            'time_start'        => substr($b->time_start, 0, 5),
            'time_end'          => substr($b->time_end, 0, 5),
            'format'            => $b->format,
            'status'            => $b->status,
            'total_amount'      => $b->total_amount,
            'prepayment_amount' => $b->prepayment_amount,
        ]);

        return response()->json(['ok' => true, 'data' => $list], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Heatmap ──────────────────────────────────────────────────────────

    public function heatmap(Request $request): JsonResponse
    {
        $query = Booking::with('hall')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->whereDate('date', '>=', now()->subMonths(3));

        if ($request->query('hall')) {
            $query->whereHas('hall', fn($q) => $q->where('name', $request->query('hall')));
        }

        $bookings = $query->get(['date', 'time_start', 'time_end']);
        $heatmap  = [];
        $max      = 0;

        foreach ($bookings as $b) {
            $dow = (int) Carbon::parse($b->date)->dayOfWeekIso - 1; // 0=Mon, 6=Sun
            [$hStart] = explode(':', $b->time_start);
            [$hEnd]   = explode(':', $b->time_end);
            for ($h = (int)$hStart; $h < (int)$hEnd; $h++) {
                $heatmap[$h][$dow] = ($heatmap[$h][$dow] ?? 0) + 1;
                if ($heatmap[$h][$dow] > $max) $max = $heatmap[$h][$dow];
            }
        }

        // Populate hall select options
        $halls = Hall::pluck('name');

        return response()->json(['ok' => true, 'heatmap' => $heatmap, 'max' => $max, 'halls' => $halls], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Action Log ───────────────────────────────────────────────────────

    public function actionLog(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 100), 500);

        $items = DB::table('action_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select('al.id', 'al.user_id', 'al.role', 'al.action',
                     'al.target_type', 'al.target_id',
                     'al.payload', 'al.ip', 'al.created_at',
                     'u.name as user_name')
            ->orderByDesc('al.created_at')
            ->limit($limit)
            ->get();

        $data = $items->map(function ($log) {
            $type = $log->target_type ?? '';
            $subject = match (true) {
                str_ends_with($type, 'Booking') => "Бронь #{$log->target_id}",
                str_ends_with($type, 'Client')  => "Клиент #{$log->target_id}",
                str_ends_with($type, 'Hall')    => "Зал #{$log->target_id}",
                $log->target_id !== null        => "#{$log->target_id}",
                default                         => null,
            };
            return [
                'id'        => $log->id,
                'user_id'   => $log->user_id,
                'user_name' => $log->user_name,
                'role'      => $log->role,
                'action'    => $log->action,
                'subject'   => $subject,
                'payload'   => $log->payload ? json_decode($log->payload, true) : null,
                'ip'        => $log->ip,
                'created_at'=> $log->created_at,
            ];
        });

        return response()->json(['ok' => true, 'data' => $data], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Users / Roles ────────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $users = User::with('roles')->orderBy('name')->get()->map(fn($u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'phone'          => $u->phone,
            'role'           => $u->getRoleNames()->first(),
            'is_blacklisted' => (bool) optional($u->client)->is_blacklisted,
            'is_active'      => (bool) ($u->is_active ?? true),
        ]);

        return response()->json(['ok' => true, 'data' => $users], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'is_blacklisted' => 'sometimes|boolean',
            'is_active'      => 'sometimes|boolean',
        ]);

        if (isset($data['is_blacklisted']) && $user->client) {
            $user->client->update(['is_blacklisted' => $data['is_blacklisted']]);
        }
        if (isset($data['is_active'])) {
            $user->update(['is_active' => $data['is_active']]);
        }

        return response()->json(['ok' => true]);
    }

    public function setUserRole(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $data = $request->validate(['role' => 'required|string|in:developer,owner,admin,client']);
        $user = User::findOrFail($id);
        $user->syncRoles([$data['role']]);

        return response()->json(['ok' => true]);
    }
}
