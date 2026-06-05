<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'form_css',
        'fields',
        'tariffs',
        'messenger_settings',
    ];

    protected $casts = [
        'fields'             => 'array',
        'tariffs'            => 'array',
        'messenger_settings' => 'array',
    ];
}
