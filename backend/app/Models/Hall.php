<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hall extends Model
{
    protected $fillable = [
        'name', 'description', 'area_m2', 'capacity',
        'equipment', 'photos', 'buffer_minutes', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'equipment'      => 'array',
        'photos'         => 'array',
        'is_active'      => 'boolean',
        'buffer_minutes' => 'integer',
        'area_m2'        => 'integer',
        'capacity'       => 'integer',
    ];

    // ── Scopes ──────────────────────────────────────────────────────────
    public function scopeActive($query) { return $query->where('is_active', true)->orderBy('sort_order'); }

    // ── Relations ───────────────────────────────────────────────────────
    public function pricingRules(): HasMany { return $this->hasMany(PricingRule::class); }
    public function bookings(): HasMany     { return $this->hasMany(Booking::class); }

    // ── Helpers ─────────────────────────────────────────────────────────
    public function getPricingFor(string $dayType): \Illuminate\Database\Eloquent\Collection
    {
        return $this->pricingRules()
            ->where('day_type', $dayType)
            ->where('is_active', true)
            ->orderBy('min_hours')
            ->get();
    }
}
