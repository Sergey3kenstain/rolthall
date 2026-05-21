<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    protected $fillable = [
        'hall_id', 'day_type', 'min_hours', 'max_hours', 'price_per_hour', 'is_active',
    ];

    protected $casts = [
        'min_hours'     => 'integer',
        'max_hours'     => 'integer',
        'price_per_hour'=> 'integer',
        'is_active'     => 'boolean',
    ];

    public function hall(): BelongsTo { return $this->belongsTo(Hall::class); }

    public function matchesDuration(int $hours): bool
    {
        return $hours >= $this->min_hours
            && ($this->max_hours === null || $hours <= $this->max_hours);
    }

    public function getRangeLabel(): string
    {
        return $this->max_hours === null
            ? "от {$this->min_hours} ч"
            : "{$this->min_hours}–{$this->max_hours} ч";
    }
}
