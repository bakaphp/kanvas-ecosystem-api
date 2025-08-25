<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToWalletAction;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Kanvas\Souk\Wallet\Transaction;


enum PurchaseTypeEnum: string
{
    case COIN_PURCHASE = 'coin_purchase';
    case SUBSCRIPTION = 'subscription';
    case ONE_TIME_PURCHASE = 'one_time_purchase';

    public static function processPurchase(Order $order): Transaction
    {
        if (! $order->get('purchase_type')) {
            throw new Exception('Purchase type is required');
        }

        return match ($order->get('purchase_type')) {
            'coin_purchase' => (new AddFundsToWalletAction($order))->execute(),
            'one_time_purchase' => (new PayFromWalletAction($order))->execute(),
            default => throw new Exception('Unsupported purchase type'),
        };
    }
}
