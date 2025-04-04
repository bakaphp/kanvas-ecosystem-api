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
        string $wooOrderId,
        string $stripeChargeId,
        ?string $newStatus = null
    ): object {
        $payloadData = [
            'payment_method' => 'stripe',
            'payment_method_title' => 'Stripe Mobile Payment',
            'transaction_id' => $stripeChargeId,
            'set_paid' => true,
            'meta_data' => [
                [
                    'key' => '_stripe_charge_id',
                    'value' => $stripeChargeId,
                ],
            ],
        ];

        if ($newStatus !== null) {
            $payloadData['status'] = $newStatus;
        }

        return $this->client->put("orders/{$wooOrderId}", $payloadData);
    }

    public function addOrderComment(
        string $wooOrderId,
        string $comment,
        bool $customerVisible = false
    ): object {
        $payload = [
            'note' => $comment,
            'customer_note' => $customerVisible,
        ];

        return $this->client->post("orders/{$wooOrderId}/notes", $payload);
    }
}
