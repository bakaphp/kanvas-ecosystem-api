<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Services;

use Automattic\WooCommerce\Client;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Client as WooCommerceClient;

class WooCommerceOrderService
{
    public Client $client;

    public function __construct(
        protected Apps $app
    ) {
        $this->client = (new WooCommerceClient($this->app))->getClient();
    }

    public function updateOrderStripePayment(
        string|int $wooOrderId,
        string $stripeChargeId,
        ?string $newStatus = null,
        array $paymentIntentData = []
    ): object {
        $paymentIntentId = $paymentIntentData['id'] ?? null;
        $chargeData = $paymentIntentData['charges']['data'][0] ?? null;

        $payloadData = [
            'payment_method'       => 'stripe',
            'payment_method_title' => 'Stripe Mobile Payment',
            'transaction_id'       => $stripeChargeId,
            'set_paid'             => true,
            'meta_data'            => [
                [
                    'key'   => '_stripe_charge_id',
                    'value' => $stripeChargeId,
                ],
                [
                    'key'   => '_stripe_intent_id',
                    'value' => $paymentIntentId,
                ],
                [
                    'key'   => '_payment_method',
                    'value' => 'stripe',
                ],
                [
                    'key'   => '_stripe_fee',
                    'value' => null, // Calculate if available
                ],
                [
                    'key'   => '_stripe_net',
                    'value' => $chargeData !== null ? $chargeData['amount_captured'] : null,
                ],
                [
                    'key'   => '_stripe_mode',
                    'value' => $paymentIntentData['livemode'] ? 'live' : 'test',
                ],
                [
                    'key'   => '_stripe_currency',
                    'value' => $paymentIntentData['currency'] ?? 'usd',
                ],
                [
                    'key'   => '_stripe_captured',
                    'value' => $chargeData !== null ? $chargeData['captured'] : true,
                ],
                [
                    'key'   => '_stripe_paid',
                    'value' => $chargeData !== null ? $chargeData['paid'] : true,
                ],
                [
                    'key'   => '_stripe_refunded',
                    'value' => $chargeData !== null ? $chargeData['refunded'] : false,
                ],
                [
                    'key'   => '_stripe_payment_method',
                    'value' => $paymentIntentData['payment_method'] ?? null,
                ], [
                    'key'   => '_stripe_source_id',
                    'value' => $paymentIntentData['payment_method'] ?? null,
                ],
                [
                    'key'   => '_payment_method_id',
                    'value' => $paymentIntentData['payment_method'] ?? null,
                ],
                [
                    'key'   => '_stripe_customer_id',
                    'value' => $paymentIntentData['customer'] ?? '',
                ],
            ],
        ];

        if ($newStatus !== null) {
            $payloadData['status'] = $newStatus;
        }

        return $this->client->put("orders/{$wooOrderId}", $payloadData);
    }

    public function addOrderComment(
        string|int $wooOrderId,
        string $comment,
        bool $customerVisible = false
    ): object {
        $payload = [
            'note'          => $comment,
            'customer_note' => $customerVisible,
        ];

        return $this->client->post("orders/{$wooOrderId}/notes", $payload);
    }
}
