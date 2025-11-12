<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Actions;

use Bavix\Wallet\Models\Transaction;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;

abstract class AddFundsToWalletActionBase
{
    public function __construct(
        protected Order $order,
    ) {
    }

    abstract public function execute(): Transaction;

    /**
     * Get the wallet holder (user or company).
     *
     * @return Model The model must use HasWalletsTrait
     */
    abstract protected function getWalletHolder(): Model;

    /**
     * Calculate the total amount to deposit based on order items.
     *
     * @throws Exception
     */
    protected function calculateTotal(): float
    {
        $total = 0.0;
        foreach ($this->order->items as $item) {
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value === null) {
                continue;
            }

            $total += (float) ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_AMOUNT->value)?->value ?? $item->getPrice());
        }

        if ($total <= 0) {
            throw new Exception('Total amount to deposit must be greater than zero.');
        }

        return $total;
    }

    /**
     * Create the transaction metadata.
     */
    protected function createTransactionMetadata(): array
    {
        return [
            'order_id' => $this->order->getId(),
            'variants' => $this->order->items->map(function (OrderItem $item): array {
                return [
                    'id' => $item->variant->getId(),
                    'name' => $item->variant->name,
                    'price' => $item->getPrice(),
                    'quantity' => $item->quantity,
                ];
            })->toArray(),
        ];
    }

    /**
     * Process the wallet transaction.
     *
     * @throws Exception
     */
    protected function processTransaction(): Transaction
    {
        $walletHolder = $this->getWalletHolder();
        $tag = ConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->order->app, ['name' => $tag]);

        $total = $this->calculateTotal();

        $transaction = $wallet->depositFloat($total);
        $transaction->meta = $this->createTransactionMetadata();
        $transaction->saveOrFail();

        return $transaction;
    }
}
