<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalEventTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentEvent;

/**
 * Event-first, deduped: the same answer delivered twice (webhook + poll, a repeated webhook, a duplicate
 * cancellation) lands as one row. `$recorded` tells the caller whether this call wrote it.
 */
class RecordFiscalEventAction
{
    public bool $recorded = false;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $attributes extra columns (reason, authority_id, requested_at…)
     */
    public function __construct(
        public readonly FiscalDocument $document,
        public readonly FiscalEventTypeEnum $type,
        public readonly string $direction,
        public readonly string $status,
        public readonly string $dedupeKey,
        public readonly array $payload = [],
        public readonly array $attributes = [],
    ) {
    }

    public function execute(): FiscalDocumentEvent
    {
        $provider = (string) ($this->document->provider ?? '');

        $existing = $this->find($provider);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $event = FiscalDocumentEvent::create([
                ...$this->attributes,
                'apps_id' => $this->document->apps_id,
                'companies_id' => $this->document->companies_id,
                'fiscal_document_id' => $this->document->getKey(),
                'provider' => $provider,
                'event_type' => $this->type,
                'direction' => $this->direction,
                'status' => $this->status,
                'payload' => $this->payload,
                'dedupe_key' => $this->dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($provider) ?? throw $e;
        }

        $this->recorded = true;

        return $event;
    }

    private function find(string $provider): ?FiscalDocumentEvent
    {
        /** @var FiscalDocumentEvent|null $event */
        $event = FiscalDocumentEvent::query()
            ->where('companies_id', $this->document->companies_id)
            ->where('provider', $provider)
            ->where('dedupe_key', $this->dedupeKey)
            ->first();

        return $event;
    }
}
