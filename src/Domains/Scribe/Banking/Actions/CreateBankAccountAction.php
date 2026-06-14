<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use RuntimeException;

/**
 * Creates a bank account row pointing at an existing Cash-class GL account.
 *
 * Validates:
 *   - gl_account_id exists in the same (apps_id, companies_id) scope
 *   - The GL account's account_type = 'asset' (bank accounts must back asset accounts)
 *
 * This is the minimal Cut B surface. Full Banking subdomain (CRUD for transactions/transfers/reconciliations)
 * comes in Phase 3.
 */
class CreateBankAccountAction
{
    public function __construct(
        public readonly BankAccountData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BankAccount
    {
        return DB::connection('accounting')->transaction(function (): BankAccount {
            $glAccount = Account::query()
                ->where('id', $this->data->gl_account_id)
                ->where('apps_id', $this->data->app->getId())
                ->where('companies_id', $this->data->company->getId())
                ->first();

            if ($glAccount === null) {
                throw new RuntimeException(
                    "GL account {$this->data->gl_account_id} not found in app {$this->data->app->getId()} "
                    . "/ company {$this->data->company->getId()}."
                );
            }

            if ($glAccount->account_type !== AccountTypeEnum::ASSET) {
                throw new RuntimeException(
                    "GL account {$glAccount->id} has type='{$glAccount->account_type->value}'; bank accounts "
                    . 'must back Asset accounts.'
                );
            }

            $bankAccount = new BankAccount();
            $bankAccount->apps_id = $this->data->app->getId();
            $bankAccount->companies_id = $this->data->company->getId();
            $bankAccount->account_name = $this->data->account_name;
            $bankAccount->gl_account_id = $this->data->gl_account_id;
            $bankAccount->currency = $this->data->currency;
            $bankAccount->account_number_last4 = $this->data->account_number_last4;
            $bankAccount->routing_number_masked = $this->data->routing_number_masked;
            $bankAccount->institution_name = $this->data->institution_name;
            $bankAccount->is_active = $this->data->is_active;
            $bankAccount->source = $this->data->source;
            $bankAccount->external_id = $this->data->external_id;
            $bankAccount->metadata = $this->data->metadata;
            $bankAccount->users_id = $this->user?->getId();
            $bankAccount->save();

            return $bankAccount->refresh();
        });
    }
}
