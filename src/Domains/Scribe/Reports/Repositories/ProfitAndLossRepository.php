<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Reports\DataTransferObject\ProfitAndLossData;
use Kanvas\Scribe\Reports\DataTransferObject\ReportLineItem;
use Kanvas\Scribe\Reports\DataTransferObject\ReportSection;
use Spatie\LaravelData\DataCollection;

/**
 * P&L for [period_start, period_end]:
 *
 *   Revenue (CR-normal, displayed positive)
 *   - COGS (DR-normal, displayed positive but reduces gross)
 *   = Gross Profit
 *   - Operating Expenses
 *   = Operating Income
 *   +/- Other Income/Expense
 *   = Net Income
 */
class ProfitAndLossRepository
{
    public function __construct(
        protected readonly AccountActivityRepository $activity = new AccountActivityRepository(),
    ) {
    }

    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency = 'USD',
    ): ProfitAndLossData {
        $accounts = $this->loadActiveAccounts($app, $company);
        $activity = $this->activity->getActivityBetween(
            $app,
            $company,
            $accounts->pluck('id')->all(),
            $periodStart,
            $periodEnd,
        );

        $revenue = $this->buildCrNormalSection(
            $accounts,
            $activity,
            'Revenue',
            [AccountTypeEnum::REVENUE->value],
        );
        $cogs = $this->buildDrNormalSection(
            $accounts,
            $activity,
            'Cost of Goods Sold',
            [AccountTypeEnum::COGS->value],
        );
        $grossProfit = $revenue->total - $cogs->total;

        $operatingExpenses = $this->buildDrNormalSection(
            $accounts,
            $activity,
            'Operating Expenses',
            [AccountTypeEnum::EXPENSE->value],
        );
        $operatingIncome = $grossProfit - $operatingExpenses->total;

        $otherIncome = $this->buildCrNormalSection(
            $accounts,
            $activity,
            'Other Income',
            [AccountTypeEnum::OTHER_INCOME->value],
        );
        $otherExpenses = $this->buildDrNormalSection(
            $accounts,
            $activity,
            'Other Expenses',
            [AccountTypeEnum::OTHER_EXPENSE->value],
        );
        $netIncome = $operatingIncome + $otherIncome->total - $otherExpenses->total;

        return new ProfitAndLossData(
            period_start: $periodStart,
            period_end: $periodEnd,
            currency: $currency,
            revenue: $revenue,
            cogs: $cogs,
            gross_profit: $grossProfit,
            operating_expenses: $operatingExpenses,
            operating_income: $operatingIncome,
            other_income: $otherIncome,
            other_expenses: $otherExpenses,
            net_income: $netIncome,
        );
    }

    /**
     * @return Collection<int, Account>
     */
    private function loadActiveAccounts(AppInterface $app, CompanyInterface $company): Collection
    {
        return Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->orderBy('account_number')
            ->get();
    }

    /**
     * CR-normal: revenue / other_income. Displayed as positive when activity is "income-shaped"
     * (more credits than debits). Net = credit - debit.
     *
     * @param  Collection<int, Account>                                    $accounts
     * @param  array<int, array{debit: float, credit: float, net: float}>  $activity
     * @param  array<int, string>                                          $types
     */
    private function buildCrNormalSection(
        Collection $accounts,
        array $activity,
        string $title,
        array $types,
    ): ReportSection {
        $lines = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            if (! in_array($account->account_type->value, $types, true)) {
                continue;
            }
            $row = $activity[$account->id] ?? null;
            if ($row === null) {
                continue;
            }
            $amount = $row['credit'] - $row['debit'];
            if ($amount === 0.0) {
                continue;
            }
            $lines[] = $this->toLineItem($account, $amount);
            $total += $amount;
        }

        return new ReportSection(
            title: $title,
            lines: new DataCollection(ReportLineItem::class, $lines),
            total: $total,
        );
    }

    /**
     * DR-normal: expense / cogs / other_expense. Net = debit - credit.
     *
     * @param  Collection<int, Account>                                    $accounts
     * @param  array<int, array{debit: float, credit: float, net: float}>  $activity
     * @param  array<int, string>                                          $types
     */
    private function buildDrNormalSection(
        Collection $accounts,
        array $activity,
        string $title,
        array $types,
    ): ReportSection {
        $lines = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            if (! in_array($account->account_type->value, $types, true)) {
                continue;
            }
            $row = $activity[$account->id] ?? null;
            if ($row === null) {
                continue;
            }
            $amount = $row['debit'] - $row['credit'];
            if ($amount === 0.0) {
                continue;
            }
            $lines[] = $this->toLineItem($account, $amount);
            $total += $amount;
        }

        return new ReportSection(
            title: $title,
            lines: new DataCollection(ReportLineItem::class, $lines),
            total: $total,
        );
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
