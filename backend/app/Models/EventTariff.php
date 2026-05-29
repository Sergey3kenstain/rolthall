<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTariff extends Model
{
    protected $fillable = ['event_id', 'name', 'has_options', 'options', 'dependent_fields', 'sort_order'];

    protected $casts = [
        'has_options'       => 'boolean',
        'options'           => 'array',
        'dependent_fields'  => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(EventTariffTier::class)->orderBy('from_date');
    }

    /** Текущая цена по дате (null если нет активного тира) */
    public function currentPrice(): ?int
    {
        $today = now()->toDateString();
        $tier  = $this->tiers()
            ->where('from_date', '<=', $today)
            ->where('to_date', '>=', $today)
            ->first();
        return $tier?->price;
    }
}
