<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiverWebhookCall extends BaseModel
{
    protected $table = 'receiver_webhook_calls';

    protected $fillable = [
        'receiver_webhooks_id',
        'uuid',
        'url',
        'headers',
        'payload',
        'exception',
        'status',
    ];

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'status' => 'string',
    ];

    public function receiverWebhook(): BelongsTo
    {
        return $this->belongsTo(ReceiverWebhook::class, 'receiver_webhooks_id');
    }
}
