<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Objects\Cart;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Souk\Wallet\Wallet;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

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

        $walletHolder = $this->resolveWalletHolder();
        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->order->app, ['name' => $tag]);
        $cart = app(Cart::class);
        $totalDebited = 0.0;
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

            $totalDebited += $price * $quantity;

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

        $payment = $this->recordWalletPayment($wallet, $tag, $totalDebited);

        $this->logPaymentEvent($payment);

        $this->order->payment_status = 'paid';
        $this->order->saveOrFail();

        $this->order->fireWorkflow(
            WorkflowEnum::AFTER_PAYMENT_INTENT->value,
            true,
            [
                'app' => $this->order->app,
                'company' => $this->order->company,
            ]
        );

        return $wallet;
    }

    protected function resolveWalletHolder(): Users|Companies
    {
        $providers = $this->order->providerCompanies()->get();

        if ($providers->count() > 1) {
            throw new ValidationException(
                'Order has multiple provider companies — cannot resolve a single wallet holder.',
                'ambiguous_wallet_holder'
            );
        }

        if ($providers->count() === 1) {
            return $providers->first();
        }

        return $this->getWalletHolder($this->order->app, $this->order->user);
    }

    /**
     * Mirror the wallet debit as a row in the payments table so wallet payments
     * are visible to the same source-of-truth as external processors (azul, cardnet, ...).
     *
     * We create the PAID row directly instead of routing through CreatePaymentAction
     * because that action's PAID branch calls Order::markAsPaid (which marks the order
     * completed and fires WorkflowEnum::UPDATED) — the wallet flow intentionally only
     * sets payment_status and fires AFTER_PAYMENT_INTENT.
     */
    protected function recordWalletPayment(Wallet $wallet, string $tag, float $amount): Payments
    {
        $idempotencyKey = $this->idempotencyKey
            ?? ('wallet_' . ($this->order->uuid ?? (string) $this->order->getId()));

        $existing = Payments::where('idempotency_key', $idempotencyKey)
            ->where('apps_id', $this->order->apps_id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->order->payments()->create([
            'amount' => $amount > 0.0 ? $amount : (float) $this->order->total_gross_amount,
            'payment_date' => date('Y-m-d'),
            'concept' => 'Wallet payment for order #' . (string) $this->order->number,
            'users_id' => $this->order->users_id,
            'companies_id' => $this->order->companies_id,
            'currency' => $this->order->currency,
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => PaymentMethodTypesEnum::WALLET->value,
            'processor' => PaymentMethodTypesEnum::WALLET->value,
            'idempotency_key' => $idempotencyKey,
            'metadata' => [
                'data' => [
                    'wallet_tag' => $tag,
                    'wallet_id' => $wallet->getKey(),
                    'wallet_holder_type' => $wallet->holder_type,
                    'wallet_holder_id' => $wallet->holder_id,
                    'source' => ($this->source ?? TransactionSourceEnum::PAYMENT)->value,
                ],
            ],
        ]);
    }

    protected function logPaymentEvent(Payments $payment): void
    {
        PaymentLogs::create([
            'apps_id' => $this->order->apps_id,
            'companies_id' => $this->order->companies_id,
            'users_id' => $this->order->users_id,
            'payments_id' => $payment->getId(),
            'payment_methods_id' => 0,
            'payable_id' => $this->order->getId(),
            'payable_type' => $this->order::class,
            'status' => 'wallet_payment',
            'metadata' => [
                'amount' => (float) $payment->amount,
                'tag' => $payment->getMetadata('wallet_tag') ?? ConfigurationEnum::WALLET_DEFAULT_NAME->value,
                'order_number' => (string) $this->order->number,
            ],
        ]);
    }
}
