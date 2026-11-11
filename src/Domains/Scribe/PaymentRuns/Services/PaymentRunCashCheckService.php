<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Collection;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\PaymentRuns\Enums\CashCheckEnum;

/**
 * Which account a currency's bills would leave from, and whether its balance covers them. The balance is
 * what Scribe knows, not what the bank has now: the feed's last balance, else the last posted statement's
 * closing balance, with the date it is as of so the person reading the run can judge how stale it is.
 */
class PaymentRunCashCheckService
{
    public function __construct(
        private readonly BankStatementCoverageService $coverage = new BankStatementCoverageService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function group(
        AppInterface $app,
        CompanyInterface $company,
        string $currency,
        float $total,
        int $lineCount
    ): array {
        $accounts = $this->coverage->activeAccounts($app, $company)
            ->filter(fn (BankAccount $account): bool => strtoupper($account->currency) === strtoupper($currency))
            ->map(fn (BankAccount $account): array => ['account' => $account, ...$this->knownBalance($account)]);

        $chosen = $this->pick($accounts);
        $balance = $chosen['amount'] ?? null;

        $check = match (true) {
            $balance === null => CashCheckEnum::UNKNOWN,
            $balance + 0.0001 >= $total => CashCheckEnum::ENOUGH,
            default => CashCheckEnum::SHORT,
        };

        return [
            'currency' => strtoupper($currency),
            'bank_account_id' => $chosen !== null ? $chosen['account']->getId() : null,
            'bank_account_name' => $chosen !== null ? $chosen['account']->account_name : null,
            'line_count' => $lineCount,
            'total' => round($total, 2),
            'known_balance' => $balance !== null ? round($balance, 2) : null,
            'balance_as_of' => $chosen['as_of'] ?? null,
            'balance_source' => $chosen['source'] ?? null,
            'cash_check' => $check->value,
            'shortfall' => $check === CashCheckEnum::SHORT ? round($total - (float) $balance, 2) : null,
        ];
    }

    /**
     * @return array{amount: float|null, as_of: string|null, source: string|null}
     */
    public function knownBalance(BankAccount $account): array
    {
        if ($account->current_balance_native !== null) {
            return [
                'amount' => (float) $account->current_balance_native,
                'as_of' => ($account->last_balance_sync_at ?? $account->updated_at)?->toDateString(),
                'source' => 'feed',
            ];
        }

        $statement = $this->coverage->isStatementDriven($account) ? $this->coverage->lastPostedStatement($account) : null;

        if ($statement?->closing_balance !== null) {
            return [
                'amount' => (float) $statement->closing_balance,
                'as_of' => $statement->period_end?->toDateString(),
                'source' => 'statement',
            ];
        }

        return ['amount' => null, 'as_of' => null, 'source' => null];
    }

    /**
     * The account with the largest known balance; an account with no known balance only when none has one.
     *
     * @param Collection<int, array{account: BankAccount, amount: float|null, as_of: string|null, source: string|null}> $accounts
     * @return array{account: BankAccount, amount: float|null, as_of: string|null, source: string|null}|null
     */
    private function pick(Collection $accounts): ?array
    {
        $known = $accounts->filter(fn (array $row): bool => $row['amount'] !== null);

        return $known->isNotEmpty()
            ? $known->sortByDesc('amount')->first()
            : $accounts->sortBy(fn (array $row): int => $row['account']->getId())->first();
    }
}
