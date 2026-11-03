<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Social\Messages\Contracts\DeliveryStatus;
use Kanvas\Social\Models\BaseModel;

/**
 * One outbound send through a provider (a Twilio SMS, a Mailgun email) and its latest delivery state.
 * `provider_message_id` is the provider's own id for it: a Twilio SID or a Mailgun Message-Id.
 */
class MessageAttempt extends BaseModel
{
    use UuidTrait;

    protected $table = 'message_attempts';
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

    public function deliveryStatus(): ?DeliveryStatus
    {
        $enum = config('kanvas.message_delivery_statuses.' . $this->provider);

        if (! is_string($enum) || ! is_subclass_of($enum, DeliveryStatus::class)) {
            return null;
        }

        return $enum::tryFrom(strtolower(trim((string) $this->current_status)));
    }
}
