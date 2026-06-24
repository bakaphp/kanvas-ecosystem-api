<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Models\Transaction;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Override;

class AddFundsToCompanyWalletAction extends AddFundsToWalletActionBase
{
    #[Override]
    public function execute(): Transaction
    {
        $company = $this->getCompany();

        UsersRepository::belongsToThisApp(
            $this->resolveValidationUser($company),
            $this->order->app,
            $company
        );

        return $this->processTransaction();
    }

    #[Override]
    protected function getWalletHolder(): Model
    {
        return $this->getCompany();
    }

    /**
     * When the company comes from order metadata, the order's user is the admin who placed the
     * recharge on the company's behalf — they are not a member of the target company, so validating
     * them against it throws "User doesn't belong to this app". Validate the company owner instead.
     * The admin stays the recorded actor via order->user in the transaction metadata.
     */
    private function resolveValidationUser(Companies $company): Users
    {
        if (! $this->resolveCompanyFromMetadata) {
            return $this->order->user;
        }

        $owner = $company->user;
        if ($owner === null) {
            throw new Exception(
                'Company ' . $company->getId() . ' has no owner to associate the wallet recharge with.'
            );
        }

        return $owner;
    }

    /**
     * Legacy default ($order->user->getCurrentCompany()) preserves the hotfix from commit
     * 84b870a8d (Jun 2025) which patched an unknown failure case in Companies::getById.
     *
     * @throws Exception
     */
    private function getCompany(): Companies
    {
        $userCompany = (int) $this->order->getMetadata('user_company_id');
        if (! $userCompany) {
            throw new Exception('User company not found in order metadata.');
        }

        if ($this->resolveCompanyFromMetadata) {
            return Companies::getById($userCompany);
        }

        return $this->order->user->getCurrentCompany();
    }
}
