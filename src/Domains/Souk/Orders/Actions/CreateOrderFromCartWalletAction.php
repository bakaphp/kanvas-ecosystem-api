<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Users\Repositories\UsersRepository;
use Override;

class CreateOrderFromCartWalletAction extends CreateBaseOrderAction
{
    use HasWalletHolderTrait;

    #[Override]
    public function execute(): Order
    {
        $this->hasEnoughWalletBalance();
        $this->request['input']['reference'] = 'wallet';
        $this->request['paymentGatewayName'] = 'wallet';

        $order = parent::execute();

        $walletTender = (float) ($order->wallet_tender ?? 0.0);

        if ($walletTender > 0.01) {
            $this->settleWalletTenderFromSnapshot($order, $walletTender);
        } else {
            new PayFromWalletAction(
                order: $order,
            )->execute();
        }

        return $order;
    }

    private function settleWalletTenderFromSnapshot(Order $order, float $walletTender): void
    {
        $snapshot = $this->resolveSnapshotForOrder($order);

        if ($snapshot === null) {
            new PayFromWalletAction(order: $order)->execute();

            return;
        }

        $snapshotUuid = $snapshot->uuid;

        $existingTransaction = $order->user
            ->createAppWallet($this->app, ['name' => 'checkout_hold'])
            ->transactions()
            ->where('meta->snapshot_uuid', $snapshotUuid)
            ->where('meta->type', 'order_payment')
            ->first();

        if ($existingTransaction !== null) {
            return;
        }

        $holdWallet = $order->user->createAppWallet($this->app, ['name' => 'checkout_hold']);
        $holdWallet->withdrawFloat($walletTender, [
            'snapshot_uuid' => $snapshotUuid,
            'type' => 'order_payment',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'idempotency_key' => 'order_payment_' . $snapshotUuid,
        ]);

        $order->markAsPaid($order->user);
    }

    #[Override]
    protected function isPrepaidAtCheckout(Order $order): bool
    {
        return true;
    }

    protected function hasEnoughWalletBalance(): void
    {
        UsersRepository::belongsToThisApp(
            $this->user,
            $this->app,
            $this->user->getCurrentCompany()
        );

        $providerCompanyId = $this->request['input']['metadata']['user_company_id'] ?? null;

        $walletHolder = $providerCompanyId !== null
            ? Companies::getById((int) $providerCompanyId)
            : $this->getWalletHolder($this->app, $this->user);

        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->app, ['name' => $tag]);

        if ($wallet->balanceFloat < $this->cart->getTotal()) {
            throw new ValidationException(
                'Insufficient wallet balance to complete the order.'
                . ' Please add funds to your wallet or choose a different payment method.',
                'wallet_balance_insufficient'
            );
        }
    }
}
