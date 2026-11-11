<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;

/**
 * Everything that happens to a fiscal document after it is sent, including every inbound provider answer.
 * `dedupe_key` is unique per company and provider, so a webhook and a poll delivering the same answer land once.
 * `applied_at` / `effects_completed_at` let a retry finish an answer a crash interrupted, instead of skipping it
 * as a duplicate.
 *
 * @property int $id
 * @property int $fiscal_document_id
 * @property string $provider
 * @property FiscalEventTypeEnum $event_type
 * @property string $direction
 * @property string $status
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property string $dedupe_key
 * @property Carbon|null $applied_at
 * @property string|null $effect
 * @property Carbon|null $effects_completed_at
 */
class FiscalDocumentEvent extends FiscalBaseModel
{
    protected $table = 'fiscal_document_events';

    protected function casts(): array
    {
        return [
            'event_type' => FiscalEventTypeEnum::class,
            'payload' => Json::class,
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'deadline_at' => 'datetime',
            'applied_at' => 'datetime',
            'effects_completed_at' => 'datetime',
        ];
    }

    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
