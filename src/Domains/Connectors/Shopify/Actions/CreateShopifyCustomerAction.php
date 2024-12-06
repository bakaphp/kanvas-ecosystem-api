<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;
use PHPShopify\ShopifySDK;

class CreateShopifyCustomerAction
{
    protected ShopifySDK $shopifySdk;

    public function __construct(
        protected People $people,
        protected Regions $region
    ) {
        $this->shopifySdk = Client::getInstance(
            $people->app,
            $people->company,
            $region
        );
    }

    /**
     * Execute the action to create or retrieve a Shopify customer.
     *
     * @return array The customer data from Shopify.
     */
    public function execute(): int
    {
        $customerEmail = $this->people->getEmails()->first()?->value;

        if (empty($customerEmail)) {
            throw new ValidationException('Email is required to create a Shopify customer.');
        }

        if ($this->people->get(ShopifyConfigurationService::getKey(
            CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
            $this->people->company,
            $this->people->app,
            $this->region
        ))) {
            return $this->people->get(ShopifyConfigurationService::getKey(
                CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
                $this->people->company,
                $this->people->app,
                $this->region
            ));
        }

        // Check if the customer already exists in Shopify
        $existingCustomers = $this->shopifySdk->Customer->get(['email' => $customerEmail]);

        if (! empty($existingCustomers)) {
            $this->saveCustomerReference($existingCustomers['id']);

            return $existingCustomers[0]['id'];
        }

        // Create a new customer in Shopify
        $customerData = $this->prepareCustomerData();

        $shopifyCustomer = $this->shopifySdk->Customer->post($customerData);

        $this->saveCustomerReference($shopifyCustomer['id']);

        return $shopifyCustomer['id'];
    }

    protected function prepareCustomerData(): array
    {
        return [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'email' => $this->people->getEmails()->first()?->value,
            'phone' => $this->people->getPhones()->first()?->value,
            'addresses' => $this->prepareAddresses(),
        ];
    }

    protected function prepareAddresses(): array
    {
        $address = $this->people->address()->first();

        if (! $address) {
            return [];
        }

        return [[
            'address1' => $address->address,
            'address2' => $address->address_2,
            'city' => $address->city,
            'province' => $address->state,
            'country' => $address->country,
            'zip' => $address->zipcode,
            //'phone' => $this->people->phone,
        ]];
    }

    protected function saveCustomerReference(int $shopifyCustomerId): void
    {
        $this->people->set(
            ShopifyConfigurationService::getKey(
                CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
                $this->people->company,
                $this->people->app,
                $this->region
            ),
            $shopifyCustomerId
        );
    }
}
