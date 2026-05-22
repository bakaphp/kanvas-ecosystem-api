<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Actions;

use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\LedgerConfigurationEnum;
use Kanvas\NervousSystem\Ledger\Events\LedgerEventBroadcast;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Throwable;

class AppendEventAction
{
    public function __construct(
        protected readonly EventData $data,
    ) {
    }

    public function execute(): Event
    {
        $event = new Event();
        $event->apps_id = $this->data->app->getId();
        $event->companies_id = $this->data->company?->getId() ?? 0;
        $event->source_domain = $this->data->sourceDomain;
        $event->source_entity_type = $this->data->sourceEntityType;
        $event->source_entity_id = $this->data->sourceEntityId;
        $event->event_type = $this->data->eventType;
        $event->actor_type = $this->data->actorType;
        $event->actor_id = $this->data->actorId;
        $event->status = $this->data->status->value;
        $event->payload = $this->data->payload;
        $event->payload_schema_version = $this->data->payloadSchemaVersion;
        $event->result = $this->data->result;
        $event->error = $this->data->error;
        $event->duration_ms = $this->data->durationMs;
        $event->correlation_id = $this->data->correlationId;
        $event->causation_id = $this->data->causationId;
        $event->occurred_at = $this->data->occurredAt ?? Carbon::now();
        $event->saveOrFail();

        $this->maybeBroadcast($event);

        return $event;
    }

    /**
     * Broadcast the event over Pusher. Default is ON — Kanvas IS the
     * nervous system, so live ledger broadcasting is core, not opt-in.
     * Apps that need to suppress it (heavy synthetic load, debug runs)
     * set `broadcast_ledger_events = false` explicitly.
     *
     * Optional allowlist via `broadcast_ledger_event_types` restricts to
     * specific event-type prefixes when set. Empty/unset = broadcast all.
     *
     * Failures are swallowed: a broadcasting outage must not break ledger
     * writes. The event row is already persisted at this point.
     */
    protected function maybeBroadcast(Event $event): void
    {
        try {
            $app = $this->data->app;

            $flag = $app->get(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENTS->value);
            // Default ON. Only suppress when the app explicitly stores a
            // falsy value (false / 0 / "0" / ""). null / missing → broadcast.
            if ($flag !== null && ! (bool) $flag) {
                return;
            }

            $allowlist = $app->get(LedgerConfigurationEnum::BROADCAST_LEDGER_EVENT_TYPES->value);

            // No allowlist set → broadcast everything. Allowlist set → only broadcast matching prefixes.
            if (is_array($allowlist) && $allowlist !== [] && ! $this->matchesAllowlist($event->event_type, $allowlist)) {
                return;
            }

            LedgerEventBroadcast::dispatch($event);
        } catch (Throwable) {
            // intentional: never let a broadcast failure roll back a ledger write
        }
    }

    /**
     * @param array<array-key, mixed> $allowlist event-type prefixes (e.g. ["plan.", "schedule.fired"])
     */
    protected function matchesAllowlist(string $eventType, array $allowlist): bool
    {
        foreach ($allowlist as $prefix) {
            if (! is_string($prefix)) {
                continue;
            }

            if ($prefix === $eventType || str_starts_with($eventType, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
