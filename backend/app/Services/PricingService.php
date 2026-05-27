<?php

namespace App\Services;

use App\Models\PricingRule;
use Carbon\Carbon;

class PricingService
{
    public function getDayType(string $date): string
    {
        return in_array(date('N', strtotime($date)), [6, 7]) ? 'weekend' : 'weekday';
    }

    public function calcHours(string $start, string $end): int
    {
        return (int) explode(':', $end)[0] - (int) explode(':', $start)[0];
    }

    public function getGuestTier(string $format, int $guestCount): string
    {
        if ($format !== 'hourly') {
            return 'any';
        }
        return $guestCount > 30 ? 'above30' : 'below30';
    }

    public function findRule(int $hallId, string $dayType, string $format, int $hours, int $guestCount): ?PricingRule
    {
        $guestTier = $this->getGuestTier($format, $guestCount);

        return PricingRule::where('hall_id', $hallId)
            ->where('booking_format', $format)
            ->where('day_type', $dayType)
            ->where('guest_tier', $guestTier)
            ->where('is_active', true)
            ->when($format !== 'allday', function ($q) use ($hours) {
                $q->where('min_hours', '<=', $hours)
                  ->where(fn($q2) => $q2->whereNull('max_hours')->orWhere('max_hours', '>=', $hours));
            })
            ->orderBy('min_hours', 'desc')
            ->first();
    }

    public function calcTotal(object|null $rule, string $format, int $hours): int
    {
        if ($rule === null) {
            return 0;
        }
        return $format === 'allday'
            ? (int) ($rule->price_per_day ?? 0)
            : (int) ($rule->price_per_hour ?? 0) * $hours;
    }

    public function calcPrepayment(int $total, int $prepayPct): int
    {
        return (int) round($total * $prepayPct / 100);
    }

    public function canRefund(Carbon $bookingStart): bool
    {
        return now()->diffInHours($bookingStart, false) >= 6;
    }
}
