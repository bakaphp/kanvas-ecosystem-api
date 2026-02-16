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
    /**
     * @var array<string, array{slug: ConfigurationEnum, amount: ConfigurationEnum, wallet: ConfigurationEnum}>
     */
    protected array $walletTypeConfig = [
        'default' => [
            'slug' => ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG,
            'amount' => ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_AMOUNT,
            'wallet' => ConfigurationEnum::WALLET_DEFAULT_NAME,
        ],
        'promotional' => [
            'slug' => ConfigurationEnum::PRODUCT_TYPE_WALLET_PROMOTIONAL_SLUG,
            'amount' => ConfigurationEnum::PRODUCT_TYPE_WALLET_PROMOTIONAL_AMOUNT,
            'wallet' => ConfigurationEnum::WALLET_PROMOTIONAL_NAME,
        ],
        'subscription' => [
            'slug' => ConfigurationEnum::PRODUCT_TYPE_WALLET_SUBSCRIPTION_SLUG,
            'amount' => ConfigurationEnum::PRODUCT_TYPE_WALLET_SUBSCRIPTION_AMOUNT,
            'wallet' => ConfigurationEnum::WALLET_SUBSCRIPTION_NAME,
        ],
    ];

    public function __construct(
        protected Order $order,
        protected bool $useOrderTotal = false,
        protected ?float $amount = null,
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
     * Calculate totals grouped by wallet type.
     *
     * @return array<string, float> Wallet type => total amount
     */
    protected function calculateTotalsByWalletType(): array
    {
        $totals = [];

        foreach ($this->order->items as $item) {
            $walletType = $this->determineWalletType($item);
            if ($walletType === null) {
                continue;
            }

            $config = $this->walletTypeConfig[$walletType];
            $amount = (float) ($item->variant->getAttributeBySlug($config['amount']->value)?->value ?? $item->getPrice());

            if (! isset($totals[$walletType])) {
                $totals[$walletType] = 0.0;
            }
            $totals[$walletType] += $amount;
        }

        return $totals;
    }

    /**
     * Determine which wallet type a product belongs to based on its attributes.
     */
    protected function determineWalletType(OrderItem $item): ?string
    {
        foreach ($this->walletTypeConfig as $type => $config) {
            if ($item->variant->getAttributeBySlug($config['slug']->value)?->value !== null) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Calculate the total amount to deposit based on:
     * 1. Explicit amount if provided
     * 2. Order total if useOrderTotal is true
     * 3. Coin variant items (default)
     *
     * @throws Exception
     */
    protected function calculateTotal(): float
    {
        if ($this->amount !== null) {
            $total = $this->amount;
        } elseif ($this->useOrderTotal) {
            $total = (float) $this->order->total_gross_amount;
        } else {
            $total = 0.0;
            foreach ($this->order->items as $item) {
                if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value === null) {
                    continue;
                }

                $total += (float) ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_AMOUNT->value)?->value ?? $item->getPrice());
            }
        }

        if ($total <= 0) {
            throw new Exception('Total amount to deposit must be greater than zero.');
        }

        return $total;
    }

    /**
     * Create the transaction metadata.
     */
    protected function createTransactionMetadata(string $walletType = 'default'): array
    {
        return [
            'order_id' => $this->order->getId(),
            'wallet_type' => $walletType,
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
     * Process transactions for all wallet types found in order items.
     *
     * @return Transaction[] Array of transactions keyed by wallet type
     * @throws Exception
     */
    protected function processTransactionsByWalletType(): array
    {
        $walletHolder = $this->getWalletHolder();
        $totalsByType = $this->calculateTotalsByWalletType();
        $transactions = [];

        foreach ($totalsByType as $walletType => $total) {
            if ($total <= 0) {
                continue;
            }

            $config = $this->walletTypeConfig[$walletType];
            $wallet = $walletHolder->createAppWallet($this->order->app, ['name' => $config['wallet']->value]);

            $transaction = $wallet->depositFloat($total);
            $transaction->meta = $this->createTransactionMetadata($walletType);
            $transaction->saveOrFail();

            $transactions[$walletType] = $transaction;
        }

        if (empty($transactions)) {
            throw new Exception('No valid wallet items found in order.');
        }

        return $transactions;
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
