<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Throwable;

/**
 * Where the current request came from, for auditing and for policy conditions.
 *
 * It derives ONLY what is reliably knowable — console vs HTTP vs app-key — and never guesses AGENT or
 * EMAIL. It cannot: HasKanvasContext::$actingAgent is tool-local with no container binding, and an
 * agent's user is routinely shared across agents, so neither the container nor the acting user can
 * tell you an agent is driving. Anything that knows says so, with during().
 *
 * For gating on provenance WITHOUT threading a caller — the usual case, since the trigger fires deep
 * inside a create — condition on the entity's own data instead. Scribe already stamps
 * source_email_message_id at creation, which is the truth about the record rather than about the
 * request, and it survives replay and backfill where request context is long gone.
 */
class ApprovalOriginService
{
    private static ?ApprovalOriginEnum $override = null;

    /**
     * Runs $work with an explicit origin — for an edge that genuinely knows (an agent kernel, an
     * inbound mail receiver). Restores the previous value even if $work throws.
     */
    public static function during(ApprovalOriginEnum $origin, callable $work): mixed
    {
        $previous = self::$override;
        self::$override = $origin;

        try {
            return $work();
        } finally {
            self::$override = $previous;
        }
    }

    public static function current(): ApprovalOriginEnum
    {
        if (self::$override !== null) {
            return self::$override;
        }

        try {
            if (app()->runningInConsole()) {
                return ApprovalOriginEnum::SYSTEM;
            }

            return auth()->check() ? ApprovalOriginEnum::UI : ApprovalOriginEnum::API;
        } catch (Throwable) {
            return ApprovalOriginEnum::SYSTEM;
        }
    }

    /**
     * Long-running workers reuse the process, so a leaked override would mislabel every later job.
     */
    public static function forget(): void
    {
        self::$override = null;
    }
}
