<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiverWebhookCall extends BaseModel
{
    use DynamicSearchableTrait;
    use HasFactory;
    use UuidTrait;

    protected $table = 'receiver_webhook_calls';

    protected $fillable = [
        'receiver_webhooks_id',
        'uuid',
        'url',
        'headers',
        'payload',
        'raw_payload',
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

    /**
     * Push the full row up as-is. Cast columns (headers / payload / results /
     * exception) come back as arrays via Eloquent, which Scout serializes
     * straight into the index record.
     */
    public function toSearchableArray(): array
    {
        return array_merge(
            $this->attributesToArray(),
            ['objectID' => self::class . "::{$this->id}"],
        );
    }

    /**
     * The searchable index name — respects Scout's configured prefix so
     * per-env indices (dev / staging / prod) stay separate.
     */
    public function searchableAs(): string
    {
        return config('scout.prefix') . 'receiver_webhook_calls';
    }
}
