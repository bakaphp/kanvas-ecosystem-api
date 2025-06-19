<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Users\Repositories\UsersRepository;
use Override;

class CreateOrderFromCartWalletAction extends CreateOrderFromCartAction
{
    #[Override]
    public function execute(): Order
    {
        $this->hasEnoughWalletBalance();
        $this->request['input']['reference'] = 'wallet';
        $this->request['paymentGatewayName'] = 'wallet';

        $order = parent::execute();

        new PayFromWalletAction(
            order: $order,
        )->execute();

        return $order;
    }

    protected function hasEnoughWalletBalance(): void
    {
        if (! isset($this->request['input']['metadata']['user_company_id'])) {
            return;
        }

        //$company = Companies::getById($this->request['input']['metadata']['user_company_id']);
        $company = $this->user->getCurrentCompany();

        UsersRepository::belongsToThisApp(
            $this->user,
            $this->app,
            $company
        );

        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $company->createAppWallet($this->app, ['name' => $tag]);

        if ($wallet->balanceFloat < $this->cart->getTotal()) {
            throw new ValidationException(
                'Insufficient wallet balance to complete the order.'
                . ' Please add funds to your wallet or choose a different payment method.',
                'wallet_balance_insufficient'
            );
        }
    }
}
