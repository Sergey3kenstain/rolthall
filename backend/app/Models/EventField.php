<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventField extends Model
{
    protected $fillable = [
        'event_id', 'label', 'placeholder', 'field_type', 'slug',
        'has_mask', 'mask_pattern', 'is_required', 'options', 'sort_order',
        'depends_on_tariff', 'dep_field', 'dep_value',
    ];

    protected $casts = [
        'has_mask'    => 'boolean',
        'is_required' => 'boolean',
        'options'     => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
