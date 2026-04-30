<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * Writes a single event row into the ledger. The synchronous path —
 * AppendToLedgerJob is the queued wrapper around this action.
 */
class AppendEventAction
{
    public function __construct(
        protected readonly EventData $data,
    ) {
    }

    public function execute(): Event
    {
        $event = new Event();
        $event->apps_id = $this->data->appsId;
        $event->companies_id = $this->data->companiesId;
        $event->source_domain = $this->data->sourceDomain;
        $event->source_entity_type = $this->data->sourceEntityType;
        $event->source_entity_id = $this->data->sourceEntityId;
        $event->event_type = $this->data->eventType;
        $event->actor_type = $this->data->actorType;
        $event->actor_id = $this->data->actorId;
        $event->status = $this->data->status->value;
        $event->payload = $this->data->payload;
        $event->result = $this->data->result;
        $event->error = $this->data->error;
        $event->duration_ms = $this->data->durationMs;
        $event->correlation_id = $this->data->correlationId;
        $event->occurred_at = $this->data->occurredAt ?? Carbon::now();
        $event->saveOrFail();

        return $event;
    }
}
