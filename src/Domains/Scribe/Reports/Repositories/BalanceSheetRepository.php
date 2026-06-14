<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Reports\DataTransferObject\BalanceSheetData;
use Kanvas\Scribe\Reports\DataTransferObject\ReportLineItem;
use Kanvas\Scribe\Reports\DataTransferObject\ReportSection;
use Spatie\LaravelData\DataCollection;

/**
 * As-of Balance Sheet. Three sections — Assets / Liabilities / Equity — each with per-account rollup.
 *
 * Sign handling:
 *   - Assets are DR-normal → expose balance as-is (positive for typical position)
 *   - Liabilities + Equity are CR-normal → flip the sign so the displayed amount is positive
 *   - Revenue/Expense don't appear on the BS — they roll into Retained Earnings via period close (deferred)
 *
 * Net Income for the current period is included as a synthetic "Current Year Earnings" line under Equity
 * (running total of revenue - expense - cogs through asOfDate). Once period close ships, this is replaced
 * by an explicit Retained Earnings movement.
 *
 * `is_balanced` returns true iff |Assets - (Liabilities + Equity)| < 0.005 (penny-rounding tolerance).
 */
class BalanceSheetRepository
{
    public function __construct(
        protected readonly AccountActivityRepository $activity = new AccountActivityRepository(),
    ) {
    }

    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $asOf,
        string $currency = 'USD',
    ): BalanceSheetData {
        $accounts = $this->loadActiveAccounts($app, $company);
        $balances = $this->activity->getBalancesAsOf(
            $app,
            $company,
            $accounts->pluck('id')->all(),
            $asOf,
        );

        $assetsSection = $this->buildAssetsSection($accounts, $balances);
        $liabilitiesSection = $this->buildLiabilitiesSection($accounts, $balances);
        $equityBase = $this->buildEquityLines($accounts, $balances);

        $netIncome = $this->computeNetIncomeThrough($accounts, $balances);

        $allEquityLines = array_merge(
            $equityBase,
            $netIncome === 0.0 ? [] : [new ReportLineItem(
                account_id: 0,
                account_number: '__cye__',
                name: 'Current Year Earnings',
                account_type: AccountTypeEnum::EQUITY->value,
                account_sub_type: null,
                amount: $netIncome,
            )],
        );

        $equityTotal = array_sum(array_map(fn (ReportLineItem $l) => $l->amount, $allEquityLines));
        $equitySection = new ReportSection(
            title: 'Equity',
            lines: new DataCollection(ReportLineItem::class, $allEquityLines),
            total: $equityTotal,
        );

        $liabPlusEquity = $liabilitiesSection->total + $equityTotal;
        $isBalanced = abs($assetsSection->total - $liabPlusEquity) < 0.005;

        return new BalanceSheetData(
            as_of: $asOf,
            currency: $currency,
            assets: $assetsSection,
            liabilities: $liabilitiesSection,
            equity: $equitySection,
            total_liabilities_and_equity: $liabPlusEquity,
            is_balanced: $isBalanced,
        );
    }

    /**
     * @return Collection<int, Account>
     */
    private function loadActiveAccounts(AppInterface $app, CompanyInterface $company): Collection
    {
        return Account::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->orderBy('account_number')
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, float>         $balances
     */
    private function buildAssetsSection(Collection $accounts, array $balances): ReportSection
    {
        $assetTypes = [AccountTypeEnum::ASSET->value];
        $lines = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            if (! in_array($account->account_type->value, $assetTypes, true)) {
                continue;
            }
            $balance = $balances[$account->id] ?? 0.0;
            if ($balance === 0.0) {
                continue;
            }
            $lines[] = $this->toLineItem($account, $balance);
            $total += $balance;
        }

        return new ReportSection(
            title: 'Assets',
            lines: new DataCollection(ReportLineItem::class, $lines),
            total: $total,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, float>         $balances
     */
    private function buildLiabilitiesSection(Collection $accounts, array $balances): ReportSection
    {
        $lines = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            if ($account->account_type->value !== AccountTypeEnum::LIABILITY->value) {
                continue;
            }
            $balance = -1 * ($balances[$account->id] ?? 0.0);
            if ($balance === 0.0) {
                continue;
            }
            $lines[] = $this->toLineItem($account, $balance);
            $total += $balance;
        }

        return new ReportSection(
            title: 'Liabilities',
            lines: new DataCollection(ReportLineItem::class, $lines),
            total: $total,
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, float>         $balances
     * @return array<int, ReportLineItem>
     */
    private function buildEquityLines(Collection $accounts, array $balances): array
    {
        $lines = [];
        foreach ($accounts as $account) {
            if ($account->account_type->value !== AccountTypeEnum::EQUITY->value) {
                continue;
            }
            $balance = -1 * ($balances[$account->id] ?? 0.0);
            if ($balance === 0.0) {
                continue;
            }
            $lines[] = $this->toLineItem($account, $balance);
        }

        return $lines;
    }

    /**
     * Net income through asOf — needed because P&L accounts haven't been closed to Retained Earnings yet
     * in Cut B (period-close → RE rollover is a separate flow, deferred).
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, float>         $balances
     */
    private function computeNetIncomeThrough(Collection $accounts, array $balances): float
    {
        $revenueTypes = [AccountTypeEnum::REVENUE->value, AccountTypeEnum::OTHER_INCOME->value];
        $expenseTypes = [
            AccountTypeEnum::EXPENSE->value,
            AccountTypeEnum::COGS->value,
            AccountTypeEnum::OTHER_EXPENSE->value,
        ];

        $netIncome = 0.0;
        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? 0.0;
            if (in_array($account->account_type->value, $revenueTypes, true)) {
                $netIncome += -1 * $balance;
            } elseif (in_array($account->account_type->value, $expenseTypes, true)) {
                $netIncome -= $balance;
            }
        }

        return $netIncome;
    }

    private function toLineItem(Account $account, float $amount): ReportLineItem
    {
        return new ReportLineItem(
            account_id: (int) $account->id,
            account_number: (string) $account->account_number,
            name: (string) $account->name,
            account_type: $account->account_type->value,
            account_sub_type: $account->account_sub_type?->value,
            amount: $amount,
        );
    }
}
