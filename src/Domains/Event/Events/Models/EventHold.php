<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Users\Models\Users;

class EventHold extends BaseModel
{
    protected $table = 'event_holds';
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public static function cleanupExpired(): int
    {
        return self::expired()->delete();
    }
}
