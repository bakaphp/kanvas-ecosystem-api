<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Reports\DataTransferObject\TrialBalanceData;
use Kanvas\Scribe\Reports\DataTransferObject\TrialBalanceRow;
use Spatie\LaravelData\DataCollection;

/**
 * Trial Balance as of a date. Every account with a non-zero net balance is included.
 *
 * For accounts whose net balance is positive (more DR than CR), the balance shows in the debit column.
 * Negative net (more CR) → credit column. The grand totals MUST balance — if `is_balanced=false`, the
 * underlying JEs are broken and one of the period-close invariants has been violated.
 */
class TrialBalanceRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $asOf,
        string $currency = 'USD',
    ): TrialBalanceData {
        $rows = DB::connection('accounting')
            ->table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('je.apps_id', $app->getId())
            ->where('je.companies_id', $company->getId())
            ->where('je.status', JournalEntryStatusEnum::POSTED->value)
            ->whereDate('je.posted_at', '<=', $asOf)
            ->where('a.is_deleted', false)
            ->groupBy('a.id', 'a.account_number', 'a.name', 'a.account_type', 'a.account_sub_type')
            ->orderBy('a.account_number')
            ->selectRaw(
                'a.id, a.account_number, a.name, a.account_type, a.account_sub_type, '
                . 'COALESCE(SUM(l.debit_base), 0) as debit_total, '
                . 'COALESCE(SUM(l.credit_base), 0) as credit_total'
            )
            ->get();

        $tbRows = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($rows as $row) {
            $debit = (float) $row->debit_total;
            $credit = (float) $row->credit_total;
            $net = $debit - $credit;

            if (abs($net) < 0.005) {
                continue;
            }

            $debitCol = $net > 0 ? $net : 0.0;
            $creditCol = $net < 0 ? -$net : 0.0;

            $tbRows[] = new TrialBalanceRow(
                account_id: (int) $row->id,
                account_number: (string) $row->account_number,
                name: (string) $row->name,
                account_type: (string) $row->account_type,
                account_sub_type: $row->account_sub_type,
                debit: $debitCol,
                credit: $creditCol,
            );
            $totalDebits += $debitCol;
            $totalCredits += $creditCol;
        }

        return new TrialBalanceData(
            as_of: $asOf,
            currency: $currency,
            rows: new DataCollection(TrialBalanceRow::class, $tbRows),
            total_debits: $totalDebits,
            total_credits: $totalCredits,
            is_balanced: abs($totalDebits - $totalCredits) < 0.005,
        );
    }
}
