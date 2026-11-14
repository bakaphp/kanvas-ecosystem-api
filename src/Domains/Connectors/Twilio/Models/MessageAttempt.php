<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Models\BaseModel;

class MessageAttempt extends BaseModel
{
    use UuidTrait;

    protected $table = 'twilio_message_attempts';
    protected $guarded = [];

    protected $casts = [
        'retry_number' => 'integer',
        'sent_at' => 'datetime',
        'terminal_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageDeliveryEvent::class, 'attempt_id');
    }
}
