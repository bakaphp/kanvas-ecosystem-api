<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiverWebhookCall extends BaseModel
{
    use UuidTrait;
    use HasFactory;

    protected $table = 'receiver_webhook_calls';

    protected $fillable = [
        'receiver_webhooks_id',
        'uuid',
        'url',
        'headers',
        'payload',
        'exception',
        'status',
        'results',
    ];

    protected $casts = [
        'headers' => Json::class,
        'payload' => Json::class,
        'status' => 'string',
        'exception' => Json::class,
        'results' => Json::class,
    ];

    public function receiverWebhook(): BelongsTo
    {
        return $this->belongsTo(ReceiverWebhook::class, 'receiver_webhooks_id');
    }
}
