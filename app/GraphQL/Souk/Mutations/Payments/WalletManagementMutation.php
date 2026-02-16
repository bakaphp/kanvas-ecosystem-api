<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Payments;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Souk\Wallet\Actions\FlushWalletAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class WalletManagementMutation
{
    public function flushUserWallet(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $admin = auth()->user();

        /** @var Users $user */
        $user = Users::getById((int) $request['user_id']);
        UsersRepository::belongsToThisApp($user, $app);

        $wallet = new FlushWalletAction(
            app: $app,
            holder: $user,
            tag: $request['tag'] ?? 'default',
            admin: $admin,
        )->execute();

        return [
            'balance' => (float) $wallet->balanceFloatNum,
            'message' => 'User wallet flushed successfully',
        ];
    }

    public function flushCompanyWallet(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $admin = auth()->user();

        /** @var Companies $company */
        $company = Companies::getById((int) $request['company_id']);
        CompaniesRepository::hasAccessToThisApp($company, $app);

        $wallet = new FlushWalletAction(
            app: $app,
            holder: $company,
            tag: $request['tag'] ?? 'default',
            admin: $admin,
        )->execute();

        return [
            'balance' => (float) $wallet->balanceFloatNum,
            'message' => 'Company wallet flushed successfully',
        ];
    }
}
