<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Carbon\Carbon;

/**
 * Temporal grounding for LLM-backed agents.
 *
 * Without an explicit "today is X" injected into the system prompt, every
 * model hallucinates dates from its training cutoff and breaks any tool
 * that takes a date/time range (scheduling, follow-ups, "next 10 business
 * days", "this week", etc.). Every Kanvas agent that does time-sensitive
 * work should `use HasTemporalContext` and inject the returned lines into
 * its system prompt.
 *
 * Timezone resolution is the caller's responsibility — pass an IANA string
 * (e.g. `America/Santo_Domingo`, `US/Central`); falls back to `UTC` when
 * unknown. For lead-scoped chats use `$lead->company->timezone`; for app
 * or company-scoped chats use whichever company owns the conversation.
 */
trait HasTemporalContext
{
    /**
     * @return list<string>
     */
    protected function temporalContextLines(?string $timezone = null): array
    {
        $tz = $timezone ?? 'UTC';
        $now = Carbon::now($tz);

        return [
            "Current date: {$now->format('l, Y-m-d')} (use this as 'today'; "
                . 'do not use dates from your training data).',
            "Current time: {$now->format('H:i')} {$tz}.",
            'When tools accept date ranges (from/to) and the user has not specified a window, '
                . 'omit those parameters and let the tool default to a sensible recent window.',
        ];
    }
}
