<?php

namespace Tests\Unit;

use App\Services\PricingService;
use Carbon\Carbon;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    private PricingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PricingService();
    }

    // ── getDayType ────────────────────────────────────────────────────────

    public function test_weekday_returns_weekday(): void
    {
        // 2026-05-25 is Monday
        $this->assertSame('weekday', $this->svc->getDayType('2026-05-25'));
    }

    public function test_saturday_returns_weekend(): void
    {
        // 2026-05-30 is Saturday
        $this->assertSame('weekend', $this->svc->getDayType('2026-05-30'));
    }

    public function test_sunday_returns_weekend(): void
    {
        // 2026-05-31 is Sunday
        $this->assertSame('weekend', $this->svc->getDayType('2026-05-31'));
    }

    // ── calcHours ─────────────────────────────────────────────────────────

    public function test_calc_hours_basic(): void
    {
        $this->assertSame(3, $this->svc->calcHours('10:00', '13:00'));
    }

    public function test_calc_hours_single(): void
    {
        $this->assertSame(1, $this->svc->calcHours('18:00', '19:00'));
    }

    public function test_calc_hours_max_slot(): void
    {
        // Full working day 10:00–22:00 = 12 hours
        $this->assertSame(12, $this->svc->calcHours('10:00', '22:00'));
    }

    // ── getGuestTier ──────────────────────────────────────────────────────

    public function test_non_hourly_format_always_any(): void
    {
        $this->assertSame('any', $this->svc->getGuestTier('event', 50));
        $this->assertSame('any', $this->svc->getGuestTier('allday', 5));
        $this->assertSame('any', $this->svc->getGuestTier('event', 1));
    }

    public function test_hourly_below30_tier(): void
    {
        $this->assertSame('below30', $this->svc->getGuestTier('hourly', 1));
        $this->assertSame('below30', $this->svc->getGuestTier('hourly', 30));
    }

    public function test_hourly_above30_tier(): void
    {
        $this->assertSame('above30', $this->svc->getGuestTier('hourly', 31));
        $this->assertSame('above30', $this->svc->getGuestTier('hourly', 200));
    }

    // ── calcTotal ─────────────────────────────────────────────────────────

    public function test_calc_total_returns_zero_for_null_rule(): void
    {
        $this->assertSame(0, $this->svc->calcTotal(null, 'hourly', 3));
    }

    public function test_calc_total_allday_uses_price_per_day(): void
    {
        $rule = $this->makeFakeRule(price_per_day: 15000, price_per_hour: 2000);
        $this->assertSame(15000, $this->svc->calcTotal($rule, 'allday', 0));
    }

    public function test_calc_total_hourly_multiplies_by_hours(): void
    {
        $rule = $this->makeFakeRule(price_per_hour: 2500);
        $this->assertSame(7500, $this->svc->calcTotal($rule, 'hourly', 3));
    }

    public function test_calc_total_event_uses_per_hour_price(): void
    {
        $rule = $this->makeFakeRule(price_per_hour: 3000);
        $this->assertSame(39000, $this->svc->calcTotal($rule, 'event', 13));
    }

    // ── calcPrepayment ────────────────────────────────────────────────────

    public function test_prepayment_50_percent(): void
    {
        $this->assertSame(5000, $this->svc->calcPrepayment(10000, 50));
    }

    public function test_prepayment_100_percent(): void
    {
        $this->assertSame(7500, $this->svc->calcPrepayment(7500, 100));
    }

    public function test_prepayment_rounds_correctly(): void
    {
        // 10001 * 50 / 100 = 5000.5 → rounds to 5001
        $this->assertSame(5001, $this->svc->calcPrepayment(10001, 50));
    }

    // ── canRefund ─────────────────────────────────────────────────────────

    public function test_can_refund_when_more_than_6h_away(): void
    {
        $future = Carbon::now()->addHours(7);
        $this->assertTrue($this->svc->canRefund($future));
    }

    public function test_cannot_refund_when_less_than_6h_away(): void
    {
        $soon = Carbon::now()->addHours(5);
        $this->assertFalse($this->svc->canRefund($soon));
    }

    public function test_cannot_refund_when_exactly_5h_59m_away(): void
    {
        $soon = Carbon::now()->addMinutes(359);
        $this->assertFalse($this->svc->canRefund($soon));
    }

    public function test_can_refund_when_exactly_6h_away(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $start = $now->copy()->addHours(6);
        $this->assertTrue($this->svc->canRefund($start));
        Carbon::setTestNow(null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeFakeRule(int $price_per_day = 0, int $price_per_hour = 0): object
    {
        return new class($price_per_day, $price_per_hour) {
            public function __construct(
                public int $price_per_day,
                public int $price_per_hour,
            ) {}
        };
    }
}
