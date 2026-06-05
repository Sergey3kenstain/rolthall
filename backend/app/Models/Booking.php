<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'client_id', 'hall_id', 'group_id',
        'date', 'time_start', 'time_end', 'duration_hours',
        'format', 'pkg_label', 'status',
        'total_amount', 'prepayment_amount',
        'payment_token', 'transaction_id',
        'hold_expires_at',
        'consent_offer', 'consent_policy', 'consent_refund',
        'notes', 'admin_notes',
    ];

    protected $casts = [
        'date'             => 'date',
        'hold_expires_at'  => 'datetime',
        'duration_hours'   => 'integer',
        'total_amount'     => 'integer',
        'prepayment_amount'=> 'integer',
        'consent_offer'    => 'boolean',
        'consent_policy'   => 'boolean',
        'consent_refund'   => 'boolean',
    ];

    // Статусы
    const STATUS_DRAFT           = 'draft';
    const STATUS_HOLD            = 'hold';
    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_PAID            = 'paid';
    const STATUS_CONFIRMED       = 'confirmed';
    const STATUS_COMPLETED       = 'completed';
    const STATUS_CANCELLED       = 'cancelled';

    // ── Relations ──────────────────────────────────────────────────────
    public function hall(): BelongsTo   { return $this->belongsTo(Hall::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }

    // ── Helpers ────────────────────────────────────────────────────────
    public function isHoldExpired(): bool
    {
        return $this->status === self::STATUS_HOLD
            && $this->hold_expires_at
            && now()->isAfter($this->hold_expires_at);
    }

    public function getTimeRangeLabel(): string
    {
        return substr($this->time_start, 0, 5) . '–' . substr($this->time_end, 0, 5);
    }

    public function getDateFormatted(): string
    {
        return $this->date->format('d.m.Y');
    }
}
