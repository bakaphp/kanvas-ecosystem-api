<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Scribe\Banking\Enums\BankStatementStatusEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankStatement;
use Kanvas\Scribe\Banking\Models\BankTransaction;

/**
 * How far each bank account's movements are known. A statement-driven account is covered through the
 * latest period_end of a posted statement; an account fed by a bank API (Mercury) is covered through its
 * latest movement, and nobody needs to be asked for its statements.
 */
class BankStatementCoverageService
{
    /** Accounts with one of these sources receive no API feed, so their movements come from statements. */
    private const array STATEMENT_DRIVEN_SOURCES = ['kanvas', 'manual', 'statement'];

    /** Coverage this many days short of today counts as behind. */
    public const int MAX_GAP_DAYS = 7;

    public function isStatementDriven(BankAccount $account): bool
    {
        return in_array($account->source, self::STATEMENT_DRIVEN_SOURCES, true);
    }

    public function coveredThrough(BankAccount $account): ?Carbon
    {
        if ($this->isStatementDriven($account)) {
            return $this->lastPostedStatement($account)?->period_end;
        }

        $latest = BankTransaction::query()
            ->where('apps_id', $account->apps_id)
            ->where('bank_account_id', $account->getId())
            ->where('is_deleted', false)
            ->max('transaction_date');

        return $latest === null ? null : Carbon::parse($latest);
    }

    public function isBehind(BankAccount $account, ?Carbon $today = null): bool
    {
        return $this->isGap($this->coveredThrough($account), $today ?? Carbon::today());
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(BankAccount $account, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $statementDriven = $this->isStatementDriven($account);
        $statement = $statementDriven ? $this->lastPostedStatement($account) : null;
        $coveredThrough = $statementDriven ? $statement?->period_end : $this->coveredThrough($account);

        return [
            'bank_account_id' => $account->getId(),
            'account_name' => $account->account_name,
            'institution' => $account->institution_name,
            'account_last4' => $account->account_number_last4,
            'currency' => $account->currency,
            'fed_by' => $statementDriven ? 'statements' : $account->source,
            'covered_through' => $coveredThrough?->toDateString(),
            'days_behind' => $coveredThrough === null ? null : max(0, (int) $coveredThrough->diffInDays($today, false)),
            'is_behind' => $this->isGap($coveredThrough, $today),
            'last_statement_id' => $statement?->getId(),
            'last_statement_closing_balance' => $statement?->closing_balance,
            'needs_movements_since' => $coveredThrough?->copy()->addDay()->toDateString(),
        ];
    }

    /**
     * @return Collection<int, BankAccount>
     */
    public function activeAccounts(AppInterface $app, CompanyInterface $company): Collection
    {
        return BankAccount::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->where('is_active', true)
            ->orderBy('account_name')
            ->get();
    }

    public function lastPostedStatement(BankAccount $account): ?BankStatement
    {
        return BankStatement::query()
            ->where('apps_id', $account->apps_id)
            ->where('companies_id', $account->companies_id)
            ->where('bank_account_id', $account->getId())
            ->where('status', BankStatementStatusEnum::POSTED->value)
            ->where('is_deleted', false)
            ->orderByDesc('period_end')
            ->first();
    }

    private function isGap(?Carbon $coveredThrough, Carbon $today): bool
    {
        return $coveredThrough === null || $coveredThrough->diffInDays($today, false) > self::MAX_GAP_DAYS;
    }
}
