<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Enums;

/**
 * What the orchestrator decided to do with a signal:
 *  - FORWARD  → ingest into the target project now (deterministic match, or classify ≥ 0.7)
 *  - APPROVAL → suggest a project, wait for a human to confirm (classify 0.4–0.7)
 *  - TRIAGE   → land in the Inbox for a human, no suggestion (classify < 0.4)
 *  - DROP     → no action needed (no project fits — internal FYI, spam, duplicate)
 */
enum RoutingOutcomeEnum: string
{
    case FORWARD = 'forward';
    case APPROVAL = 'approval';
    case TRIAGE = 'triage';
    case DROP = 'drop';
}
