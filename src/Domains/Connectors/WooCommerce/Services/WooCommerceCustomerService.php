<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Services;

use Automattic\WooCommerce\Client;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Client as WooCommerceClient;
use Kanvas\Connectors\WooCommerce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

class WooCommerceCustomerService
{
    public Client $client;

    public function __construct(
        protected Apps $app
    ) {
        $this->client = (new WooCommerceClient($this->app))->getClient();
    }

    public function getCustomerIdByEmail(string $email): int
    {
        $customers = $this->client->get('customers', [
            'email'    => $email,
            'per_page' => 1, // Limit to 1 result for efficiency
        ]);

        // If we found a matching customer, return their ID
        if (! empty($customers) && is_array($customers)) {
            return (int) $customers[0]->id;
        }

        return 0;
    }

    public function createCustomer(People $people, string $email): int
    {
        if ($people->get(CustomFieldEnum::WOOCOMMERCE_ID->value) !== null) {
            return (int) $people->get(CustomFieldEnum::WOOCOMMERCE_ID->value);
        }

        $customerData = [
            'email'      => $email,
            'first_name' => $people->firstname,
            'last_name'  => $people->lastname,
            'username'   => $email, // Using email as username
            'password'   => Str::random(12), // Generate a random password
        ];

        /*  // Optionally add address information if available
         if ($this->order->billing_address_id !== null) {
             $billingAddress = $this->order->billingAddress;
             $customerData['billing'] = [
                 'first_name' => $people->firstname,
                 'last_name' => $people->lastname,
                 'address_1' => $billingAddress->address,
                 'address_2' => $billingAddress->address_2 ?? '',
                 'city' => $billingAddress->city,
                 'state' => $billingAddress->state,
                 'postcode' => $billingAddress->zip,
                 'country' => $billingAddress->country ? $billingAddress->country->code : '',
                 'email' => $email,
                 'phone' => $this->order->user_phone ?? '',
             ];
         }

         if ($this->order->shipping_address_id !== null) {
             $shippingAddress = $this->order->shippingAddress;
             $customerData['shipping'] = [
                 'first_name' => $people->firstname,
                 'last_name' => $people->lastname,
                 'address_1' => $shippingAddress->address,
                 'address_2' => $shippingAddress->address_2 ?? '',
                 'city' => $shippingAddress->city,
                 'state' => $shippingAddress->state,
                 'postcode' => $shippingAddress->zip,
                 'country' => $shippingAddress->country ? $shippingAddress->country->code : '',
             ];
         } */

        $response = $this->client->post('customers', $customerData);

        // Store the WooCommerce customer ID in the Kanvas people record
        if (isset($response->id)) {
            $people->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $response->id);

            return (int) $response->id;
        }

        return 0;
    }
}
