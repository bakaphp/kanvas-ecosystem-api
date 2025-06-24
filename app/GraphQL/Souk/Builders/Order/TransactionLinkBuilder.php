<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Order;

use Kanvas\Souk\Orders\Models\Order;

class TransactionLinkBuilder
{
    // @todo: create order links by provider, currently is just for stripe
    public function getLink(Order $order): string
    {
        $url = 'https://dashboard.stripe.com';
        if (app()->environment() !== 'production') {
            $url .= '/test';
        }
        if ($order->checkout_token !== null && strlen($order->checkout_token)) {
            $paymentIntentId = explode('_secret_', $order->checkout_token)[0];
            $url .= "/payments/$paymentIntentId";
        }

        return $url;
    }
}
