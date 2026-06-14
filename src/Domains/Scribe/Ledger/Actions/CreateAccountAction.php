<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\DataTransferObject\AccountData;
use Kanvas\Scribe\Ledger\Models\Account;
use RuntimeException;

/**
 * Creates a Chart-of-Accounts row.
 *
 * Validates:
 *   - account_number unique per (apps_id, companies_id)
 *   - parent_account_id exists in same scope (if set)
 */
class CreateAccountAction
{
    public function __construct(
        public readonly AccountData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Account
    {
        return DB::connection('accounting')->transaction(function (): Account {
            $this->assertAccountNumberUnique();
            $this->assertParentExistsIfSet();

            $account = new Account();
            $account->apps_id = $this->data->app->getId();
            $account->companies_id = $this->data->company->getId();
            $account->account_number = $this->data->account_number;
            $account->name = $this->data->name;
            $account->description = $this->data->description;
            $account->account_type = $this->data->account_type->value;
            $account->account_sub_type = $this->data->account_sub_type?->value;
            $account->parent_account_id = $this->data->parent_account_id;
            $account->currency = $this->data->currency;
            $account->is_active = $this->data->is_active;
            $account->is_system = $this->data->is_system;
            $account->source = $this->data->source;
            $account->external_id = $this->data->external_id;
            $account->metadata = $this->data->metadata;
            $account->users_id = $this->user?->getId();
            $account->save();

            return $account->refresh();
        });
    }

    private function assertAccountNumberUnique(): void
    {
        $exists = Account::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('account_number', $this->data->account_number)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Account number '{$this->data->account_number}' is already used in this company's chart of accounts."
            );
        }
    }

    private function assertParentExistsIfSet(): void
    {
        if ($this->data->parent_account_id === null) {
            return;
        }

        $parent = Account::query()
            ->where('id', $this->data->parent_account_id)
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->first();

        if ($parent === null) {
            throw new RuntimeException(
                "parent_account_id={$this->data->parent_account_id} not found in app/company scope."
            );
        }
    }
}
