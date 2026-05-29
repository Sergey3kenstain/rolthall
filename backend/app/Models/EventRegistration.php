<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    protected $fillable = [
        'event_id', 'phone', 'tg_user_id', 'max_user_id', 'fields_data',
        'tariff_id', 'selected_option', 'payment_status', 'payment_amount',
        'payment_order_id', 'tbank_payment_id', 'paid_at', 'ticket_sent', 'client_id',
    ];

    protected $casts = [
        'fields_data'    => 'array',
        'payment_amount' => 'integer',
        'paid_at'        => 'datetime',
        'ticket_sent'    => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(EventTariff::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Первое поле из fields_data — обычно имя */
    public function getDisplayNameAttribute(): string
    {
        if (!empty($this->fields_data)) {
            return array_values($this->fields_data)[0] ?? $this->phone;
        }
        return $this->phone;
    }
}
