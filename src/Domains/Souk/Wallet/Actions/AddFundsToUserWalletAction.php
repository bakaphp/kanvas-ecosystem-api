<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Models\Transaction;
use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;

class AddFundsToUserWalletAction
{
    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): Transaction
    {
        $user = $this->order->user;

        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $user->createAppWallet($this->order->app, ['name' => $tag]);
        $total = 0;
        foreach ($this->order->items as $item) {
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value === null) {
                continue;
            }

            $total += (float) ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_AMOUNT->value)?->value ?? $item->getPrice());
        }
        if ($total <= 0) {
            throw new Exception('Total amount to deposit must be greater than zero.');
        }

        $transaction = $wallet->depositFloat($total);
        $transaction->meta = [
            'order_id' => $this->order->getId(),
            'variants' => $this->order->items->map(function ($item) {
                return [
                    'id' => $item->variant->getId(),
                    'name' => $item->variant->name,
                    'price' => $item->getPrice(),
                    'quantity' => $item->quantity,
                ];
            })->toArray(),
        ];
        $transaction->saveOrFail();

        return $transaction;
    }
}
