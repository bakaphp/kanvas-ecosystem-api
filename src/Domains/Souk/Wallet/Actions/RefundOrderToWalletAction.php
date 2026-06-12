<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Actions\CreateRefundAction;
use Kanvas\Souk\Payments\DataTransferObject\RefundPayment;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Wallet\DataTransferObject\WalletRefund;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Souk\Wallet\Wallet;

class RefundOrderToWalletAction
{
    use HasWalletHolderTrait;

    public function __construct(
        protected readonly WalletRefund $data,
        protected readonly ?TransactionSourceEnum $source = null,
    ) {
    }

    public function execute(): Wallet
    {
        $order = $this->data->order;

        if (! $order->hasTag([ConfigurationEnum::WALLET_CREDIT_TAG->value])) {
            throw new ValidationException('Order was not paid with wallet');
        }

        $maxRefundable = $this->getMaxRefundableAmount();
        $previouslyRefunded = $this->getPreviouslyRefundedAmount();
        $refundAmount = $this->data->amount ?? ($maxRefundable - $previouslyRefunded);

        if ($refundAmount <= 0) {
            throw new ValidationException('Order has already been fully refunded');
        }

        if (($previouslyRefunded + $refundAmount) > $maxRefundable) {
            throw new ValidationException(
                'Refund amount exceeds maximum refundable. Max remaining: ' . (string) ($maxRefundable - $previouslyRefunded)
            );
        }

        $walletHolder = $this->getWalletHolder($this->data->app, $order->user);
        $wallet = $walletHolder->createAppWallet($this->data->app, ['name' => $this->data->tag]);

        $audit = new BuildWalletTransactionMetaAction(
            source: $this->source ?? TransactionSourceEnum::REFUND_TECHNICAL,
            actorUserId: $this->data->user->getId(),
            externalReference: $order->uuid ?? (string) $order->getId(),
            reason: $this->data->reason,
            additional: [
                'service' => $order->orderType?->name,
                'order_id' => $order->getId(),
                'order_number' => (string) $order->number,
                'type' => 'order_refund',
                'description' => 'Wallet refund for order #' . (string) $order->number,
                'refunded_by' => $this->data->user->getId(),
            ],
        )->execute();

        // Refund row created before the wallet credit so a post-deposit failure leaves a visible
        // PENDING row, not a silent credit. No outer DB transaction: bavix wallet ops manage their
        // own and the balance sync breaks when nested.
        $walletPayment = $this->findRefundableWalletPayment($order, $refundAmount);
        $refund = null;

        if ($walletPayment !== null && $order->company !== null) {
            $refund = new CreateRefundAction(
                new RefundPayment(
                    app: $this->data->app,
                    company: $order->company,
                    user: $this->data->user,
                    payment: $walletPayment,
                    amount: $refundAmount,
                    reason: $this->data->reason,
                )
            )->execute();
        }

        $wallet->depositFloat($refundAmount, $audit);

        $refund?->markAsCompleted([
            'wallet_refund' => true,
            'tag' => $this->data->tag,
        ]);

        $totalRefunded = $previouslyRefunded + $refundAmount;

        $order->set('wallet_refund_total', $totalRefunded);

        /** @var array<int, array<string, mixed>> $refundHistory */
        $refundHistory = $order->get('wallet_refunds') ?? [];
        $refundHistory[] = [
            'tag' => $this->data->tag,
            'amount' => $refundAmount,
            'refunded_by' => $this->data->user->getId(),
            'reason' => $this->data->reason,
            'refunded_at' => now()->toIso8601String(),
        ];
        $order->set('wallet_refunds', $refundHistory);

        $order->addTag('wallet_refunded');

        $this->logRefundEvent($order, $refundAmount, $walletPayment);

        return $wallet->refresh();
    }

    protected function findRefundableWalletPayment(Order $order, float $refundAmount): ?Payments
    {
        $payment = $order->payments()
            ->where('payment_method', PaymentMethodTypesEnum::WALLET->value)
            ->where('status', PaymentStatusEnum::PAID->value)
            ->latest()
            ->first();

        if ($payment === null) {
            return null;
        }

        $remaining = (float) $payment->amount - $payment->getRefundedAmount();

        return $refundAmount <= $remaining ? $payment : null;
    }

    protected function getMaxRefundableAmount(): float
    {
        $order = $this->data->order;

        // Prefer what actually landed in the payments table; wallet-cart order totals can be 0.
        $walletPayment = $order->payments()
            ->where('payment_method', PaymentMethodTypesEnum::WALLET->value)
            ->where('status', PaymentStatusEnum::PAID->value)
            ->latest()
            ->first();

        if ($walletPayment !== null) {
            return (float) $walletPayment->amount;
        }

        $walletCredit = $order->get(ConfigurationEnum::WALLET_CREDIT->value);

        if (is_array($walletCredit) && isset($walletCredit['amount'])) {
            return (float) $walletCredit['amount'];
        }

        return (float) $order->total_gross_amount;
    }

    protected function getPreviouslyRefundedAmount(): float
    {
        $order = $this->data->order;
        $totalRefunded = $order->get('wallet_refund_total');

        return $totalRefunded !== null ? (float) $totalRefunded : 0.0;
    }

    protected function logRefundEvent(Order $order, float $refundAmount, ?Payments $payment = null): void
    {
        PaymentLogs::create([
            'apps_id' => $this->data->app->getId(),
            'companies_id' => $order->companies_id,
            'users_id' => $this->data->user->getId(),
            'payments_id' => $payment?->getId() ?? 0,
            'payment_methods_id' => 0,
            'payable_id' => $order->getId(),
            'payable_type' => $order::class,
            'status' => 'wallet_refund',
            'metadata' => [
                'amount' => $refundAmount,
                'reason' => $this->data->reason,
                'tag' => $this->data->tag,
                'refunded_by' => $this->data->user->getId(),
            ],
        ]);
    }
}
