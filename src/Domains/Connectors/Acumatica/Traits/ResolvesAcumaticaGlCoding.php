<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Traits;

use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportAccount;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Scribe\Ledger\Actions\CreateAccountAction;
use Kanvas\Scribe\Ledger\DataTransferObject\Account as AccountData;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use RuntimeException;

/**
 * Resolves an Acumatica GL account/subaccount code to its Scribe id, so imported lines mirror the
 * ERP's coding. Accounts are created on the fly from the source (the pull stays self-sufficient);
 * subaccounts are looked up only — they arrive wholesale via PullSubaccountsAction, so a not-yet-synced
 * one leaves the line's subaccount null rather than blocking the import.
 *
 * The using action must expose `app`, `company`, `user` and `acumaticaCompanyId`.
 */
trait ResolvesAcumaticaGlCoding
{
    /** @var array<string, int|null> accountCd → resolved Scribe account id (per-run cache) */
    private array $accountCache = [];

    /** @var array<string, int|null> subCode → resolved Scribe subaccount id (per-run cache) */
    private array $subaccountCache = [];

    private function resolveAccountId(string $accountCd): ?int
    {
        if ($accountCd === '') {
            return null;
        }

        if (array_key_exists($accountCd, $this->accountCache)) {
            return $this->accountCache[$accountCd];
        }

        $account = $this->findAccount($accountCd) ?? $this->createAccount($accountCd);
        $id = $account?->getId();

        return $this->accountCache[$accountCd] = $id !== null ? (int) $id : null;
    }

    private function resolveSubaccountId(?string $subCode): ?int
    {
        if ($subCode === null || $subCode === '') {
            return null;
        }

        if (array_key_exists($subCode, $this->subaccountCache)) {
            return $this->subaccountCache[$subCode];
        }

        $id = Subaccount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('sub_code', $subCode)
            ->where('is_deleted', false)
            ->value('id');

        return $this->subaccountCache[$subCode] = $id !== null ? (int) $id : null;
    }

    private function findAccount(string $accountCd): ?Account
    {
        return Account::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_number', $accountCd)
            ->where('is_deleted', false)
            ->first();
    }

    private function createAccount(string $accountCd): ?Account
    {
        $row = SqlClient::connection($this->app)
            ->table('Account')
            ->where('CompanyID', $this->acumaticaCompanyId)
            ->where('AccountCD', $accountCd)
            ->select(['AccountID', 'AccountCD', 'Description', 'Type'])
            ->first();

        if ($row === null) {
            return null;
        }

        $account = AcumaticaImportAccount::from((array) $row);

        if ($account->accountType === null) {
            return null;
        }

        try {
            new CreateAccountAction(
                new AccountData(
                    app: $this->app,
                    company: $this->company,
                    account_number: $account->accountNumber,
                    name: $account->name,
                    account_type: $account->accountType,
                    description: $account->description,
                    source: AcumaticaImportAccount::SOURCE,
                    external_id: $account->externalId !== '' ? $account->externalId : null,
                ),
                $this->user,
            )->execute();
        } catch (RuntimeException) {
            // Lost a race / created concurrently — fall through to the re-lookup below.
        }

        return $this->findAccount($accountCd);
    }
}
