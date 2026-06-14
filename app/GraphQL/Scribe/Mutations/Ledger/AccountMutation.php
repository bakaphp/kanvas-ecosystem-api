<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Ledger;

use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Ledger\Actions\CreateAccountAction;
use Kanvas\Scribe\Ledger\Actions\UpdateAccountAction;
use Kanvas\Scribe\Ledger\DataTransferObject\AccountData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;

class AccountMutation
{
    public function create(mixed $rootValue, array $request): Account
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateAccountAction(
            data: $this->buildData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Account
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Account $account */
        $account = Account::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateAccountAction(
            account: $account,
            data: $this->buildData($request['input'], $app, $company),
            user: $user,
        )->execute();
    }

    private function buildData(array $input, $app, $company): AccountData
    {
        return new AccountData(
            app: $app,
            company: $company,
            account_number: (string) $input['account_number'],
            name: (string) $input['name'],
            account_type: AccountTypeEnum::from((string) $input['account_type']),
            currency: (string) ($input['currency'] ?? 'USD'),
            description: $input['description'] ?? null,
            account_sub_type: isset($input['account_sub_type'])
                ? AccountSubTypeEnum::from((string) $input['account_sub_type'])
                : null,
            parent_account_id: isset($input['parent_account_id']) ? (int) $input['parent_account_id'] : null,
            is_active: $input['is_active'] ?? true,
            source: $input['source'] ?? 'kanvas',
            external_id: $input['external_id'] ?? null,
            metadata: $input['metadata'] ?? null,
        );
    }
}
