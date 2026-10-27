<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

use Baka\Support\Str;

/**
 * Ledger reasons that mean the engine broke, as opposed to a gate doing its job (too_soon,
 * ai_mode_off, agent declined...). The daily summary and `lead:follow-up-status` report these as
 * failed and keep them out of skipped.
 */
enum FollowUpFailureReasonEnum: string
{
    case AGENT_CALL_FAILED = 'agent_call_failed';
    case CRM_SYNC_FAILED = 'crm_sync_failed';
    case NO_REACHABLE_CHANNEL = 'no_reachable_channel';
    case NO_SESSION = 'no_session';
    case SEND_FAILED = 'send_failed';
    case JOB_FAILED = 'job_failed';

    /**
     * `agent_call_failed: <error>` and `agent: <text>` carry free text after the colon; the key is
     * what precedes it.
     */
    public static function reasonKey(string $reason): string
    {
        $colon = strpos($reason, ':');

        return $colon === false ? $reason : substr($reason, 0, $colon);
    }

    public static function reasonDetail(string $reason): ?string
    {
        $colon = strpos($reason, ':');

        return $colon === false ? null : Str::trimToNull(substr($reason, $colon + 1));
    }

    public static function isFailure(string $reason): bool
    {
        return self::tryFrom(self::reasonKey($reason)) !== null;
    }
}
