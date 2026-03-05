<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Objects\Cart;
use Kanvas\Exceptions\ValidationException;
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
        $useVariantCreditInsteadOfVariantPrice = $this->order->app->get(ConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value);

        if ($wallet->balance < $this->order->total_amount) {
            throw new ValidationException('Insufficient funds in wallet');
        }

        foreach ($this->order->items as $item) {
            //if they are coins we cant deduct from the wallet
            if (
                $item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value !== null ||
                $item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_USER_WALLET_COIN_SLUG->value)?->value !== null
            ) {
                continue;
            }

            $variantCredit = (float) ($item->variant->getAttributeBySlug(ConfigurationEnum::VARIANT_WALLET_CREDIT_AMOUNT->value)?->value ?? 0.0);

            $price = $useVariantCreditInsteadOfVariantPrice && $variantCredit > 0.0
                ? $variantCredit
                : (float) $item->getPrice();
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                continue;
            }

            $cart = $cart->withItem(
                product: $item->variant,
                quantity: $quantity,
                pricePerItem: (string) ($price * 100.0)
            );
        }

        $cart = $cart->withMeta([
            'order_id' => $this->order->getId(),
            'order_number' => (string) $this->order->number,
            'type' => 'order_payment',
            'description' => 'Wallet payment for order #' . (string) $this->order->number,
        ]);

        $wallet->payCart($cart);

        $this->order->addTag(ConfigurationEnum::WALLET_CREDIT_TAG->value);

        return $wallet;
    }
}
