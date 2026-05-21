<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'user_id', 'name', 'phone', 'email', 'telegram_username',
        'is_blacklisted', 'blacklist_reason', 'bookings_count', 'total_paid',
    ];

    protected $casts = [
        'is_blacklisted' => 'boolean',
        'bookings_count' => 'integer',
        'total_paid'     => 'integer',
    ];

    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function bookings(): HasMany   { return $this->hasMany(Booking::class); }
}
