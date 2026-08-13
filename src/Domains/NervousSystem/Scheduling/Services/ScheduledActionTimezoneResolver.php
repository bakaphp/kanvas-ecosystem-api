<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Services;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Resolves the IANA timezone a scheduled action is interpreted in — the zone the LLM's
 * "tomorrow at 3pm" and any `recurrence_cron` are evaluated against.
 *
 * Precedence: company timezone → user timezone → app default (UTC). Each candidate is
 * validated as a real IANA zone first, so a blank/garbage company value falls through to
 * the next rather than corrupting `run_at` (tenant timezone strings are not guaranteed IANA).
 */
class ScheduledActionTimezoneResolver
{
    public function resolve(?Companies $company, ?Users $user): string
    {
        foreach ([$company?->timezone, $user?->timezone] as $candidate) {
            if (is_string($candidate) && $this->isValidTimezone($candidate)) {
                return $candidate;
            }
        }

        $appDefault = (string) config('app.timezone');

        return $this->isValidTimezone($appDefault) ? $appDefault : 'UTC';
    }

    public function isValidTimezone(string $timezone): bool
    {
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * Parse an agent-supplied "YYYY-MM-DD HH:MM" wall-clock string in the resolved zone and return it as
     * UTC. Empty input is not an error (it means "no time given"); a malformed string throws.
     */
    public function parseInZone(?string $value, string $timezone): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value, $timezone)->utc();
    }
}
