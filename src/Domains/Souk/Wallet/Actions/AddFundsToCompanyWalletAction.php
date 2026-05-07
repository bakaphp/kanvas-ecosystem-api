<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Models\Transaction;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Repositories\UsersRepository;
use Override;

class AddFundsToCompanyWalletAction extends AddFundsToWalletActionBase
{
    #[Override]
    public function execute(): Transaction
    {
        $company = $this->getCompany();

        UsersRepository::belongsToThisApp(
            $this->order->user,
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
     * @throws Exception
     */
    private function getCompany(): Companies
    {
        $userCompany = $this->order->getMetadata('user_company_id');
        if (! $userCompany) {
            throw new Exception('User company not found in order metadata.');
        }

        ///$company = Companies::getById($userCompany); hotfix while we figure it out
        return $this->order->user->getCurrentCompany();
    }
}
