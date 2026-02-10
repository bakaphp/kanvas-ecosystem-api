<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Objects\Cart;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Souk\Wallet\Wallet;
use Kanvas\Users\Repositories\UsersRepository;

class PayFromWalletAction
{
    use HasWalletHolderTrait;

    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): Wallet
    {
        $company = $this->order->user->getCurrentCompany();

        UsersRepository::belongsToThisApp(
            $this->order->user,
            $this->order->app,
            $company
        );

        $walletHolder = $this->getWalletHolder($this->order->app, $this->order->user);
        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->order->app, ['name' => $tag]);
        $cart = app(Cart::class);

        foreach ($this->order->items as $item) {
            //if they are coins we cant deduct from the wallet
            if (
                $item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value !== null ||
                $item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_USER_WALLET_COIN_SLUG->value)?->value !== null
            ) {
                continue;
            }
            $cart = $cart->withItem(
                product: $item->variant,
                quantity: (int) $item->quantity,
                pricePerItem: (string) ($item->getPrice() * 100)
            );
        }

        $wallet->payCart($cart);

        $this->order->addTag(ConfigurationEnum::WALLET_CREDIT_TAG->value);

        return $wallet;
    }
}
