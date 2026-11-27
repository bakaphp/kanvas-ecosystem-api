<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use RuntimeException;

/**
 * Looks up system accounts by sub_type for a given (app, company) pair.
 *
 * JE composers use this to find canonical accounts (Accounts Receivable, Sales Tax Payable, etc.) without
 * hard-coding ids — the ids are tenant-specific because each company has its own COA seeded by
 * ChartOfAccountsSeederService.
 *
 * Throws if a required system account is missing — that means the company didn't get a COA seed and the
 * tenant onboarding flow has a bug.
 */
class AccountResolverService
{
    public function bySubType(
        AppInterface $app,
        CompanyInterface $company,
        AccountSubTypeEnum $subType
    ): Account {
        $account = $this->bySubTypeOrNull($app, $company, $subType);

        if ($account === null) {
            throw new RuntimeException(
                "No active account with sub_type='{$subType->value}' found for app {$app->getId()} "
                . "/ company {$company->getId()}. "
                . 'Did the company get a COA seed via ChartOfAccountsSeederService?'
            );
        }

        return $account;
    }

    /**
     * The currency the company keeps its books in: the one its chart of accounts was seeded with, read off the
     * receivables account. Null for a company with no chart yet.
     */
    public function ledgerCurrency(AppInterface $app, CompanyInterface $company): ?string
    {
        $currency = $this->bySubTypeOrNull($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE)?->currency;

        return $currency === null ? null : strtoupper($currency);
    }

    /**
     * Same as bySubType but returns null instead of throwing — for sub-types that may or may not exist
     * depending on country (DR-specific accounts like DR_ITBIS_PAYABLE).
     */
    public function bySubTypeOrNull(
        AppInterface $app,
        CompanyInterface $company,
        AccountSubTypeEnum $subType
    ): ?Account {
        return Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_sub_type', $subType->value)
            ->where('is_active', true)
            ->first();
    }
}
