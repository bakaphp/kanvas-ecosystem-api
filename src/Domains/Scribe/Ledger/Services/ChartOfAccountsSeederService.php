<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * Seeds the standard Chart of Accounts on company creation.
 *
 * Data lives in AccountSubTypeEnum (one case per sub-type, each carrying its own metadata via methods).
 * This service is a thin iterator — pick the right set (US-default OR US-default + DR-extension), then
 * persist each enum case as an `accounts` row. Idempotent: re-running skips any account_number that exists.
 *
 * @see AccountSubTypeEnum — the canonical catalog
 * @see plan §7.7 — "System accounts are undeletable"
 */
class ChartOfAccountsSeederService
{
    public function seedUsDefault(
        int $appsId,
        int $companiesId,
        ?string $defaultCurrency = 'USD',
        ?int $userId = null
    ): int {
        return $this->seed(
            $appsId,
            $companiesId,
            $defaultCurrency,
            $userId,
            AccountSubTypeEnum::usDefaultSet(),
        );
    }

    public function seedDominicanRepublicDefault(int $appsId, int $companiesId, ?int $userId = null): int
    {
        return $this->seed(
            $appsId,
            $companiesId,
            'DOP',
            $userId,
            AccountSubTypeEnum::dominicanRepublicSet(),
        );
    }

    /**
     * Country-aware entry point. Picks the right COA set based on the company's country_code config.
     *
     * @param string|null $countryCode 'DO' for Dominican Republic, anything else (or null) falls back to US-default.
     */
    public function seedForCountry(
        int $appsId,
        int $companiesId,
        ?string $countryCode = null,
        ?int $userId = null
    ): int {
        return match (strtoupper((string) $countryCode)) {
            'DO' => $this->seedDominicanRepublicDefault($appsId, $companiesId, $userId),
            default => $this->seedUsDefault(
                $appsId,
                $companiesId,
                defaultCurrency: 'USD',
                userId: $userId,
            ),
        };
    }

    /**
     * @param list<AccountSubTypeEnum> $subTypes
     * @return int Number of new accounts inserted (skipped existing rows are not counted).
     */
    private function seed(
        int $appsId,
        int $companiesId,
        ?string $defaultCurrency,
        ?int $userId,
        array $subTypes
    ): int {
        $existingNumbers = Account::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->pluck('account_number')
            ->all();

        $inserted = 0;
        DB::connection('accounting')->transaction(function () use ($subTypes, $existingNumbers, $appsId, $companiesId, $defaultCurrency, $userId, &$inserted) {
            foreach ($subTypes as $subType) {
                if (in_array($subType->defaultAccountNumber(), $existingNumbers, true)) {
                    continue;
                }

                $account = new Account();
                $account->apps_id = $appsId;
                $account->companies_id = $companiesId;
                $account->account_number = $subType->defaultAccountNumber();
                $account->name = $subType->defaultName();
                $account->account_type = $subType->defaultAccountType();
                $account->account_sub_type = $subType->value;
                $account->currency = $subType->defaultCurrency() ?? $defaultCurrency;
                $account->is_active = true;
                $account->is_system = $subType->isSystem();
                $account->source = 'kanvas';
                $account->users_id = $userId;
                $account->save();
                $inserted++;
            }
        });

        return $inserted;
    }
}
