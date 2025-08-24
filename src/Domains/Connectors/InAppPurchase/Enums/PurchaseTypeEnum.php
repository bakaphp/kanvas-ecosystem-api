<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToWalletAction;
use Kanvas\Souk\Wallet\Transaction;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Exception;

enum PurchaseTypeEnum: string
{
    case COIN_PURCHASE = 'coin_purchase';
    case SUBSCRIPTION = 'subscription';
    case ONE_TIME_PURCHASE = 'one_time_purchase';

    public static function processPurchase(Order $order): Transaction
    {
        if (! $order->get('purchase_type')) {
            throw new Exception('Invalid purchase type');
        }

        return match ($order->get('purchase_type')) {
            'coin_purchase' => (new AddFundsToWalletAction($order))->execute(),
            'one_time_purchase' => (new PayFromWalletAction($order))->execute(),
        };
    }
}
