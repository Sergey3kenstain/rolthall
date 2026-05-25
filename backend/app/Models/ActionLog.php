<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActionLog extends Model
{
    public $timestamps = false;
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'role', 'action',
        'target_type', 'target_id',
        'payload', 'ip', 'user_agent',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public static function write(
        string   $action,
        ?int     $userId     = null,
        ?string  $role       = null,
        ?string  $targetType = null,
        ?int     $targetId   = null,
        array    $payload    = [],
        ?Request $request    = null
    ): void {
        try {
            static::create([
                'user_id'     => $userId,
                'role'        => $role,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'payload'     => $payload ?: null,
                'ip'          => $request?->ip(),
                'user_agent'  => $request ? substr($request->userAgent() ?? '', 0, 191) : null,
            ]);
        } catch (\Throwable) {
            // никогда не ломаем основной флоу
        }
    }
}
