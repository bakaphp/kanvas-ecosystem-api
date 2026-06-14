<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\DataTransferObject\AccountData;
use Kanvas\Scribe\Ledger\Models\Account;
use RuntimeException;

/**
 * Updates a Chart-of-Accounts row.
 *
 * System accounts (`is_system=true`) have most fields locked — only is_active, description, and metadata
 * can change. Renaming/renumbering an AR / AP / Cash / RetainedEarnings row would break the AccountResolverService
 * lookups that sub-ledger composers rely on.
 *
 * Account-type changes are also blocked even on non-system accounts — flipping ASSET → LIABILITY mid-life
 * would break trial balance math against the historical JEs.
 */
class UpdateAccountAction
{
    public function __construct(
        public readonly Account $account,
        public readonly AccountData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Account
    {
        return DB::connection('accounting')->transaction(function (): Account {
            $account = $this->account;

            if ($account->account_type !== $this->data->account_type) {
                throw new RuntimeException(
                    "Cannot change account_type on existing account {$account->id} "
                    . "(current='{$account->account_type->value}', requested='{$this->data->account_type->value}'). "
                    . 'Account-type changes invalidate historical trial-balance math.'
                );
            }

            if ($account->is_system) {
                // Allow only is_active, description, metadata
                $account->is_active = $this->data->is_active;
                $account->description = $this->data->description ?? $account->description;
                $account->metadata = $this->data->metadata ?? $account->metadata;
                $account->save();

                return $account->refresh();
            }

            $this->assertAccountNumberUniqueOnRename($account);

            $account->account_number = $this->data->account_number;
            $account->name = $this->data->name;
            $account->description = $this->data->description;
            $account->account_sub_type = $this->data->account_sub_type?->value;
            $account->parent_account_id = $this->data->parent_account_id;
            $account->currency = $this->data->currency;
            $account->is_active = $this->data->is_active;
            $account->external_id = $this->data->external_id ?? $account->external_id;
            $account->metadata = $this->data->metadata ?? $account->metadata;
            $account->save();

            return $account->refresh();
        });
    }

    private function assertAccountNumberUniqueOnRename(Account $account): void
    {
        if ($account->account_number === $this->data->account_number) {
            return;
        }

        $exists = Account::query()
            ->where('apps_id', $account->apps_id)
            ->where('companies_id', $account->companies_id)
            ->where('account_number', $this->data->account_number)
            ->where('id', '!=', $account->id)
            ->where('is_deleted', false)
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                "Account number '{$this->data->account_number}' is already used in this company's chart of accounts."
            );
        }
    }
}
