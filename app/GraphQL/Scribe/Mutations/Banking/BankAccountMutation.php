<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Banking;

use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;

class BankAccountMutation
{
    public function create(mixed $rootValue, array $request): BankAccount
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateBankAccountAction(
            data: new BankAccountData(
                app: $app,
                company: $company,
                account_name: (string) $input['account_name'],
                gl_account_id: (int) $input['gl_account_id'],
                currency: (string) $input['currency'],
                account_number_last4: $input['account_number_last4'] ?? null,
                routing_number_masked: $input['routing_number_masked'] ?? null,
                institution_name: $input['institution_name'] ?? null,
                is_active: $input['is_active'] ?? true,
                source: $input['source'] ?? 'kanvas',
                external_id: $input['external_id'] ?? null,
                metadata: $input['metadata'] ?? null,
            ),
            user: $user,
        )->execute();
    }
}
