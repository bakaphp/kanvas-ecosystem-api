<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Objects\Cart;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Souk\Wallet\Wallet;
use Kanvas\Users\Repositories\UsersRepository;

class PayFromWalletAction
{
    use HasWalletHolderTrait;

    public function __construct(
        protected Order $order,
        protected readonly ?TransactionSourceEnum $source = null,
        protected readonly ?string $idempotencyKey = null,
        protected readonly ?int $actorUserId = null,
        protected readonly ?string $externalReference = null,
        protected readonly ?string $reason = null,
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

        $audit = new BuildWalletTransactionMetaAction(
            source: $this->source ?? TransactionSourceEnum::PAYMENT,
            idempotencyKey: $this->idempotencyKey,
            actorUserId: $this->actorUserId ?? $this->order->user->getId(),
            externalReference: $this->externalReference ?? $this->order->uuid ?? (string) $this->order->getId(),
            reason: $this->reason,
            additional: [
                'service' => $this->order->orderType?->name,
                'order_id' => $this->order->getId(),
                'order_number' => (string) $this->order->number,
                'type' => 'order_payment',
                'description' => 'Wallet payment for order #' . (string) $this->order->number,
            ],
        )->execute();

        $cart = $cart->withMeta($audit);

        $wallet->payCart($cart);

        $this->order->addTag(ConfigurationEnum::WALLET_CREDIT_TAG->value);

        $this->logPaymentEvent();

        return $wallet;
    }

    protected function logPaymentEvent(): void
    {
        PaymentLogs::create([
            'apps_id' => $this->order->apps_id,
            'companies_id' => $this->order->companies_id,
            'users_id' => $this->order->users_id,
            'payments_id' => 0,
            'payment_methods_id' => 0,
            'payable_id' => $this->order->getId(),
            'payable_type' => $this->order::class,
            'status' => 'wallet_payment',
            'metadata' => [
                'amount' => (float) $this->order->total_gross_amount,
                'tag' => ConfigurationEnum::WALLET_DEFAULT_NAME->value,
                'order_number' => (string) $this->order->number,
            ],
        ]);
    }
}
