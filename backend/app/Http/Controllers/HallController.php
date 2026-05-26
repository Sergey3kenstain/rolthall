<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HallController extends Controller
{
    /**
     * Список активных залов
     * GET /api/halls
     */
    public function index(): JsonResponse
    {
        $halls = Hall::active()->with('pricingRules')->get();

        return response()->json($halls->map(fn(Hall $h) => $this->formatHall($h)), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Детали зала
     * GET /api/halls/{id}
     */
    public function show(Hall $hall): JsonResponse
    {
        if (!$hall->is_active) {
            return response()->json(['error' => 'Зал недоступен'], 404);
        }

        return response()->json($this->formatHall($hall->load('pricingRules')), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Тарифы зала для выбранной даты
     * GET /api/halls/{id}/pricing?date=2026-05-21
     */
    public function pricing(Hall $hall, Request $request): JsonResponse
    {
        $date       = $request->input('date', now()->toDateString());
        $format     = $request->input('format', 'hourly'); // hourly|event|allday
        $guestCount = (int)$request->input('guest_count', 1);
        $dayType    = $this->getDayType($date);

        // Гостевой тир только для почасовой
        $guestTier = 'any';
        if ($format === 'hourly') {
            $guestTier = $guestCount > 30 ? 'above30' : 'below30';
        }

        $rules = $hall->pricingRules()
            ->where('booking_format', $format)
            ->where('day_type', $dayType)
            ->where('guest_tier', $guestTier)
            ->where('is_active', true)
            ->get();

        // Для формата all — возвращаем оба варианта (со светом и без)
        $allRules = $format === 'allday'
            ? $hall->pricingRules()->where('booking_format', 'allday')->where('is_active', true)->get()
            : collect();

        return response()->json([
            'hall_id'     => $hall->id,
            'date'        => $date,
            'day_type'    => $dayType,
            'format'      => $format,
            'guest_tier'  => $guestTier,
            'rules'       => $rules->map(fn($r) => [
                'id'                 => $r->id,
                'min_hours'          => $r->min_hours,
                'max_hours'          => $r->max_hours,
                'price_per_hour'     => $r->price_per_hour,
                'price_per_day'      => $r->price_per_day,
                'prepayment_percent' => $r->prepayment_percent,
                'description'        => $r->description,
            ]),
            'allday_options' => $allRules->map(fn($r) => [
                'id'                 => $r->id,
                'day_type'           => $r->day_type,
                'price_per_day'      => $r->price_per_day,
                'prepayment_percent' => $r->prepayment_percent,
                'description'        => $r->description,
            ]),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Доступные слоты на дату
     * GET /api/halls/{id}/availability?date=2026-05-21
     */
    public function availability(Hall $hall, Request $request): JsonResponse
    {
        $request->validate(['date' => 'required|date|after_or_equal:today']);
        $date = $request->input('date');

        $bookedRanges = Booking::where('hall_id', $hall->id)
            ->where('date', $date)
            ->whereNotIn('status', [Booking::STATUS_CANCELLED, Booking::STATUS_DRAFT])
            ->get(['time_start', 'time_end']);

        $slots = [];
        for ($hour = 9; $hour <= 22; $hour++) {
            $start = sprintf('%02d:00:00', $hour);
            $end   = sprintf('%02d:00:00', $hour + 1);

            $booked = $bookedRanges->first(fn($b) =>
                $b->time_start <= $start && $b->time_end > $start
            );

            $slots[] = [
                'hour'   => $hour,
                'start'  => substr($start, 0, 5),
                'end'    => substr($end, 0, 5),
                'booked' => (bool) $booked,
            ];
        }

        return response()->json([
            'hall_id' => $hall->id,
            'date'    => $date,
            'slots'   => $slots,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ── Private ───────────────────────────────────────────────────────────

    private function formatHall(Hall $hall): array
    {
        return [
            'id'             => $hall->id,
            'name'           => $hall->name,
            'description'    => $hall->description,
            'area_m2'        => $hall->area_m2,
            'capacity'       => $hall->capacity,
            'equipment'      => $hall->equipment ?? [],
            'photos'         => $hall->photos ?? [],
            'buffer_minutes' => $hall->buffer_minutes,
        ];
    }

    private function getDayType(string $date): string
    {
        $dow = date('N', strtotime($date)); // 1=Пн, 7=Вс
        return in_array($dow, [6, 7]) ? 'weekend' : 'weekday';
    }
}
