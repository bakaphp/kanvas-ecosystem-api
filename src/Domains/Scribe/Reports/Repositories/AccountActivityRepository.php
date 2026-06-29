<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;

/**
 * The GL-side primitive every report sits on top of. Two reads:
 *
 *   - getAccountBalanceAsOf — running balance for one account at a point in time
 *   - getAccountActivity     — debits + credits for one account in a period
 *
 * Both filter to POSTED journal entries only (drafts + reversed are excluded), and to the tenant's
 * (apps_id, companies_id) at the journal_entries level (lines have no tenant cols of their own).
 *
 * Returns base-currency floats (the company's reporting currency). Native amounts are intentionally
 * NOT used for cross-account aggregation — every report rolls up in base.
 */
class AccountActivityRepository
{
    /**
     * Running balance for one GL account through `asOfDate` (inclusive).
     *
     * Sign convention:
     *   - Returns SUM(debit_base) - SUM(credit_base)
     *   - Positive for asset/expense accounts (DR-normal), negative for liability/equity/revenue (CR-normal)
     *   - Reports rebase the sign per section (BalanceSheet flips liabilities + equity to display positive)
     */
    public function getAccountBalanceAsOf(
        AppInterface $app,
        CompanyInterface $company,
        int $accountId,
        Carbon $asOfDate,
    ): float {
        $row = DB::connection('accounting')
            ->table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('je.apps_id', $app->getId())
            ->where('je.companies_id', $company->getId())
            ->where('je.status', JournalEntryStatusEnum::POSTED->value)
            ->whereDate('je.posted_at', '<=', $asOfDate)
            ->where('l.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(l.debit_base), 0) - COALESCE(SUM(l.credit_base), 0) as bal')
            ->first();

        return (float) ($row->bal ?? 0);
    }

    /**
     * Bulk version of getAccountBalanceAsOf for many accounts at once.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, float> keyed by account_id (missing accounts = 0)
     */
    public function getBalancesAsOf(
        AppInterface $app,
        CompanyInterface $company,
        array $accountIds,
        Carbon $asOfDate,
    ): array {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::connection('accounting')
            ->table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('je.apps_id', $app->getId())
            ->where('je.companies_id', $company->getId())
            ->where('je.status', JournalEntryStatusEnum::POSTED->value)
            ->whereDate('je.posted_at', '<=', $asOfDate)
            ->whereIn('l.account_id', $accountIds)
            ->groupBy('l.account_id')
            ->selectRaw(
                'l.account_id, '
                . 'COALESCE(SUM(l.debit_base), 0) as debit_total, '
                . 'COALESCE(SUM(l.credit_base), 0) as credit_total'
            )
            ->get();

        $out = [];
        foreach ($accountIds as $id) {
            $out[$id] = 0.0;
        }
        foreach ($rows as $row) {
            $out[(int) $row->account_id] = (float) $row->debit_total - (float) $row->credit_total;
        }

        return $out;
    }

    /**
     * Bulk debit + credit totals across [periodStart, periodEnd] inclusive.
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, array{debit: float, credit: float, net: float}>
     */
    public function getActivityBetween(
        AppInterface $app,
        CompanyInterface $company,
        array $accountIds,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::connection('accounting')
            ->table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('je.apps_id', $app->getId())
            ->where('je.companies_id', $company->getId())
            ->where('je.status', JournalEntryStatusEnum::POSTED->value)
            ->whereDate('je.posted_at', '>=', $periodStart)
            ->whereDate('je.posted_at', '<=', $periodEnd)
            ->whereIn('l.account_id', $accountIds)
            ->groupBy('l.account_id')
            ->selectRaw(
                'l.account_id, '
                . 'COALESCE(SUM(l.debit_base), 0) as debit_total, '
                . 'COALESCE(SUM(l.credit_base), 0) as credit_total'
            )
            ->get();

        $out = [];
        foreach ($accountIds as $id) {
            $out[$id] = ['debit' => 0.0, 'credit' => 0.0, 'net' => 0.0];
        }
        foreach ($rows as $row) {
            $debit = (float) $row->debit_total;
            $credit = (float) $row->credit_total;
            $out[(int) $row->account_id] = [
                'debit' => $debit,
                'credit' => $credit,
                'net' => $debit - $credit,
            ];
        }

        return $out;
    }
}
