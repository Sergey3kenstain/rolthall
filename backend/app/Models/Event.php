<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'title', 'slug', 'description', 'form_css', 'poster_path', 'event_date',
        'tag', 'status', 'payments_enabled', 'allow_multiple_registrations',
        'messenger_settings', 'created_by',
    ];

    protected $casts = [
        'event_date'                   => 'date',
        'payments_enabled'             => 'boolean',
        'allow_multiple_registrations' => 'boolean',
        'messenger_settings'           => 'array',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(EventField::class)->orderBy('sort_order');
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(EventTariff::class)->orderBy('sort_order');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster_path
            ? asset('storage/' . $this->poster_path)
            : null;
    }
}
