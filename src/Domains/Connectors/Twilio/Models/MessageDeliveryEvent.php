<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Social\Models\BaseModel;

class MessageDeliveryEvent extends BaseModel
{
    use UuidTrait;

    protected $table = 'twilio_message_delivery_events';
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(MessageAttempt::class, 'attempt_id');
    }
}
