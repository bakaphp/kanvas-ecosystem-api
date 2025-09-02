<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Objects\Cart;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Wallet;
use Kanvas\Users\Repositories\UsersRepository;

class PayFromWalletAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): Wallet
    {
        $userCompany = $this->order->getMetadata('user_company_id');
        if (! $userCompany) {
            throw new Exception('User company not found in order metadata.');
        }

        //$company = Companies::getById($userCompany);
        $company = $this->order->user->getCurrentCompany();

        UsersRepository::belongsToThisApp(
            $this->order->user,
            $this->order->app,
            $company
        );

        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $company->createAppWallet($this->order->app, ['name' => $tag]);
        //$total = 0;
        $cart = app(Cart::class);

        foreach ($this->order->items as $item) {
            //if they are coins we cant deduct from the wallet
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value !== null) {
                continue;
            }
            //$total += $item->getTotal();
            $cart = $cart->withItem(
                product: $item->variant,
                quantity: (int) $item->quantity,
                pricePerItem: (string) ($item->getPrice() * 100)
            );
        }

        // $wallet->withdrawFloat($total);
        $wallet->payCart($cart);

        return $wallet;
    }
}
