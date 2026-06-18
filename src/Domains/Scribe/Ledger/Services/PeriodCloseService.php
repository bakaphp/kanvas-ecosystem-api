<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Exceptions\ClosedFiscalPeriodException;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;

/**
 * Gates JE posting based on fiscal_period.status.
 *
 * - OPEN: anyone with normal posting permission can post.
 * - SOFT_CLOSED: requires explicit override (Bouncer ability `accounting.post_to_closed_period`).
 * - HARD_CLOSED: rejects unconditionally.
 * - No matching fiscal_period: also rejected (you must open a period before posting to its date range).
 *
 * @see plan §7.7 — "Period close gates posting"
 */
class PeriodCloseService
{
    public const OVERRIDE_ABILITY = 'accounting.post_to_closed_period';

    /**
     * @throws ClosedFiscalPeriodException
     */
    public function assertCanPostAt(
        int $appsId,
        int $companiesId,
        Carbon $postedAt,
        ?UserInterface $user = null,
    ): FiscalPeriod {
        $period = $this->findPeriodFor($appsId, $companiesId, $postedAt);

        if ($period === null) {
            throw new ClosedFiscalPeriodException(
                "No fiscal period found for {$postedAt->toDateString()} in app {$appsId} / company {$companiesId}. "
                . 'Open a period covering this date before posting.'
            );
        }

        return match ($period->status) {
            FiscalPeriodStatusEnum::OPEN => $period,
            FiscalPeriodStatusEnum::SOFT_CLOSED => $this->checkOverride($period, $user),
            FiscalPeriodStatusEnum::HARD_CLOSED => throw new ClosedFiscalPeriodException(
                "Fiscal period {$period->id} ({$period->period_start->toDateString()} – {$period->period_end->toDateString()}) "
                . 'is HARD_CLOSED. Postings are not allowed under any circumstances.'
            ),
        };
    }

    public function findPeriodFor(int $appsId, int $companiesId, Carbon $postedAt): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->whereDate('period_start', '<=', $postedAt)
            ->whereDate('period_end', '>=', $postedAt)
            ->first();
    }

    private function checkOverride(FiscalPeriod $period, ?UserInterface $user): FiscalPeriod
    {
        if ($user !== null && method_exists($user, 'can') && $user->can(self::OVERRIDE_ABILITY)) {
            return $period;
        }

        throw new ClosedFiscalPeriodException(
            "Fiscal period {$period->id} ({$period->period_start->toDateString()} – {$period->period_end->toDateString()}) "
            . 'is SOFT_CLOSED. Posting requires the `' . self::OVERRIDE_ABILITY . '` ability.'
        );
    }
}
