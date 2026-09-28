<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

/**
 * Config keys for the continuation loop.
 *
 * Resolution is agent-first, app-fallback, off-by-default. Agent-first is the point: the pilot runs
 * the new loop while its siblings on the same tenant keep the old behaviour, so a bad decision costs
 * one agent rather than one company.
 */
enum PlanLoopConfigEnum: string
{
    /** Set on an Agent (custom field) or an App (setting) to route wakes through the decision. */
    case CONTINUATION_ENABLED = 'plan_continuation_enabled';

    /** Optional per-app override of PlanContinuationAction::DEFAULT_MAX_WAKES. */
    case DEFAULT_MAX_WAKES = 'plan_default_max_wakes';

    /**
     * Spend ceiling for a single plan, in USD. Unset means unlimited, deliberately: a hard cap
     * nobody has tuned kills real work in its first week, whereas the warn threshold below teaches
     * you what the right number is before you enforce one.
     */
    case MAX_COST_USD = 'plan_max_cost_usd';

    /** Fraction of MAX_COST_USD at which the owner is warned once. Defaults to 0.8. */
    case WARN_AT_FRACTION = 'plan_warn_at_fraction';
}
