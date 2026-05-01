<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Enums;

/**
 * App-level configuration keys for the Nervous System ledger.
 * Stored on `apps_settings` via `$app->set(...)` / read via `$app->get(...)`.
 */
enum LedgerConfigurationEnum: string
{
    /**
     * Bool. When true, every ledger event is broadcast over Pusher to the
     * per-tenant + per-app channels. Defaults to false (no broadcast).
     */
    case BROADCAST_LEDGER_EVENTS = 'broadcast_ledger_events';

    /**
     * Array<string>. Optional allowlist of event-type prefixes to broadcast
     * (e.g. ["plan.", "schedule.fired"]). If absent, every event broadcasts
     * (assuming BROADCAST_LEDGER_EVENTS is true).
     */
    case BROADCAST_LEDGER_EVENT_TYPES = 'broadcast_ledger_event_types';
}
