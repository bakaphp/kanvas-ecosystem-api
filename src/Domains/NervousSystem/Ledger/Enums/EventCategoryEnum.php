<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Enums;

/**
 * User-facing classification of ledger events for the Pulse stream.
 *
 * Internal/observability events (cron rollups, internal wake-up
 * dispatches) intentionally do NOT map to any category — Event::category
 * returns null for them, the subscription doesn't broadcast them, and
 * the FE filter pills don't have to know they exist.
 *
 * The mapping rules live in Event::categoryFor() — keep them there so
 * the policy is in one file.
 */
enum EventCategoryEnum: string
{
    /** Incoming events: leads, messages, webhooks, things flowing in. */
    case SIGNAL = 'SIGNAL';

    /** Context assembly + analysis: "Kanvas understood the situation." */
    case UNDERSTAND = 'UNDERSTAND';

    /** Agent decisions, plan creation/approval, routing choices. */
    case DECIDE = 'DECIDE';

    /** Executions: task completions, integration calls, replies posted. */
    case ACT = 'ACT';

    /** Failures, errors, escalations — anything that warrants attention. */
    case WARNING = 'WARNING';
}
