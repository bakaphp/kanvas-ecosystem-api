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
 * Opens a calendar-month fiscal period on demand when a posting date has none.
 *
 * Feeds (bank transactions, ERP imports) carry dates we don't control — they arrive whenever the external
 * system says they arrived, including months nobody opened. PostJournalEntryAction hard-throws with no
 * period, so a feed without this would break on its first out-of-range date.
 *
 * Deliberately does NOT auto-open for Kanvas-native documents: a user creating an invoice dated three years
 * out should hit the guard, not silently mint a period. Only ingest paths call this.
 *
 * Extracted from Acumatica's PullJournalEntriesAction::ensurePeriod so the bank feed and the ERP import
 * share one implementation.
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
}
