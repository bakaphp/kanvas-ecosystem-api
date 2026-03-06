<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Wallet\DataTransferObject\WalletRefund;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Souk\Wallet\Wallet;

class RefundOrderToWalletAction
{
    use HasWalletHolderTrait;

    public function __construct(
        protected readonly WalletRefund $data,
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

        $wallet->depositFloat($refundAmount, [
            'order_id' => $order->getId(),
            'order_number' => (string) $order->number,
            'type' => 'order_refund',
            'description' => 'Wallet refund for order #' . (string) $order->number,
            'reason' => $this->data->reason,
            'refunded_by' => $this->data->user->getId(),
        ]);

        $totalRefunded = $previouslyRefunded + $refundAmount;

        $order->addMetadata('wallet_refund', [
            'tag' => $this->data->tag,
            'amount' => $refundAmount,
            'total_refunded' => $totalRefunded,
            'refunded_by' => $this->data->user->getId(),
            'reason' => $this->data->reason,
            'refunded_at' => now()->toIso8601String(),
        ]);

        $order->addTag('wallet_refunded');

        return $wallet->refresh();
    }

    protected function getMaxRefundableAmount(): float
    {
        $order = $this->data->order;
        $walletCredit = $order->getMetadata(ConfigurationEnum::WALLET_CREDIT->value);

        if (is_array($walletCredit) && isset($walletCredit['amount'])) {
            return (float) $walletCredit['amount'];
        }

        return (float) $order->total_gross_amount;
    }

    protected function getPreviouslyRefundedAmount(): float
    {
        $order = $this->data->order;
        $walletRefund = $order->getMetadata('wallet_refund');

        if (is_array($walletRefund) && isset($walletRefund['total_refunded'])) {
            return (float) $walletRefund['total_refunded'];
        }

        return 0.0;
    }
}
