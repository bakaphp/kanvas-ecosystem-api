<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Actions\OpenFiscalPeriodAction;
use RuntimeException;

/**
 * Opens calendar-month fiscal periods on demand.
 *
 * Two callers with two different reaches:
 *   - Feeds (bank transactions, ERP imports) carry dates we don't control, so they open whatever month a
 *     row lands in through ensureOpenPeriodFor().
 *   - Every other posting goes through PostJournalEntryAction, which only opens inside the rolling window
 *     (the current calendar year plus the next), so postings never stop on January 1st. A document dated
 *     three years out still hits the "no fiscal period" guard rather than silently minting one.
 */
class FiscalPeriodAutoOpenService
{
    public function __construct(
        protected readonly PeriodCloseService $periodCloseService = new PeriodCloseService(),
    ) {
    }

    /**
     * Idempotent. Returns true when a period covering $postedAt exists (or was just created), false when
     * one couldn't be opened — the caller then lets PostJournalEntryAction surface the rejection.
     */
    public function ensureOpenPeriodFor(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $postedAt,
        ?UserInterface $user = null,
    ): bool {
        $existing = $this->periodCloseService->findPeriodFor(
            $app->getId(),
            $company->getId(),
            $postedAt,
        );

        if ($existing !== null) {
            return true;
        }

        try {
            new OpenFiscalPeriodAction(
                app: $app,
                company: $company,
                periodStart: $postedAt->copy()->startOfMonth(),
                periodEnd: $postedAt->copy()->endOfMonth(),
                user: $user,
            )->execute();

            return true;
        } catch (RuntimeException) {
            // The calendar month overlaps a custom (non-calendar) fiscal period boundary. We can't guess the
            // tenant's intended shape, so leave it to PostJournalEntryAction to reject explicitly.
            return false;
        }
    }

    /**
     * The posting-path variant: opens the month only when it falls inside the rolling window.
     */
    public function ensureOpenPeriodWithinWindow(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $postedAt,
        ?UserInterface $user = null,
    ): bool {
        if (! $this->isWithinRollingWindow($postedAt)) {
            return false;
        }

        return $this->ensureOpenPeriodFor(
            $app,
            $company,
            $postedAt,
            $user
        );
    }

    /**
     * Opens every month of the rolling window that has no period yet.
     *
     * @return int how many months were opened
     */
    public function openRollingWindow(
        AppInterface $app,
        CompanyInterface $company,
        ?UserInterface $user = null,
        ?Carbon $today = null,
    ): int {
        [$cursor, $windowEnd] = $this->rollingWindow($today);
        $opened = 0;

        while ($cursor->lte($windowEnd)) {
            $hadPeriod = $this->periodCloseService->findPeriodFor($app->getId(), $company->getId(), $cursor) !== null;

            if (! $hadPeriod && $this->ensureOpenPeriodFor(
                $app,
                $company,
                $cursor->copy(),
                $user
            )) {
                $opened++;
            }

            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $opened;
    }

    public function isWithinRollingWindow(Carbon $date, ?Carbon $today = null): bool
    {
        [$windowStart, $windowEnd] = $this->rollingWindow($today);

        return $date->betweenIncluded($windowStart, $windowEnd);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rollingWindow(?Carbon $today): array
    {
        $today ??= Carbon::today();

        return [
            $today->copy()->startOfYear(),
            $today->copy()->addYearNoOverflow()->endOfYear(),
        ];
    }
}
