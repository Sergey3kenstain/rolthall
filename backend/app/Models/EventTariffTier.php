<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTariffTier extends Model
{
    protected $fillable = ['tariff_id', 'from_date', 'to_date', 'price'];

    protected $casts = [
        'from_date' => 'date',
        'to_date'   => 'date',
        'price'     => 'integer',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(EventTariff::class);
    }
}
