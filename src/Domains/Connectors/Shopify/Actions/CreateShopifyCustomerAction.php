<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Support\Str;
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

    public function execute(): int
    {
        $customerEmail = $this->people->getEmails()->first()?->value;

        if (empty($customerEmail)) {
            throw new ValidationException('Email is required to create a Shopify customer.');
        }

        // Check if we already have a stored Shopify customer ID
        $existingShopifyId = $this->getStoredShopifyCustomerId();
        if ($existingShopifyId) {
            return $existingShopifyId;
        }

        // Check if the customer already exists in Shopify
        $existingCustomers = $this->shopifySdk->Customer->get(['email' => $customerEmail]);

        if (! empty($existingCustomers)) {
            $matchedCustomer = $this->findExactEmailMatch($existingCustomers, $customerEmail);
            $this->saveCustomerReference($matchedCustomer['id']);

            return $matchedCustomer['id'];
        }

        // Create a new customer in Shopify
        $customerData = $this->prepareCustomerData();
        $shopifyCustomer = $this->shopifySdk->Customer->post($customerData);
        $this->saveCustomerReference($shopifyCustomer['id']);

        return $shopifyCustomer['id'];
    }

    protected function getStoredShopifyCustomerId(): ?int
    {
        $shopifyCustomerId = $this->people->get(ShopifyConfigurationService::getKey(
            CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
            $this->people->company,
            $this->people->app,
            $this->region
        ));

        return $shopifyCustomerId ? (int) $shopifyCustomerId : null;
    }

    protected function findExactEmailMatch(array $customers, string $email): array
    {
        // Iterate through customers to find exact email match
        foreach ($customers as $customer) {
            if (isset($customer['email']) && strtolower($customer['email']) === strtolower($email)) {
                return $customer;
            }
        }

        // If no exact match found, return the first customer as fallback
        return $customers[0];
    }

    protected function prepareCustomerData(): array
    {
        $phone = $this->people->getPhones()->first()?->value;
        if (! empty($phone)) {
            $phone = Str::sanitizePhoneNumber($phone);
            $phone = Str::startsWith($phone, '+1') ? $phone : '+1' . $phone;
        }

        $customerData = [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'email' => $this->people->getEmails()->first()?->value,
            'addresses' => $this->prepareAddresses(),
        ];

        if (! empty($phone)) {
            $customerData['phone'] = $phone;
        }
        
        return $customerData;
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
            'country' => $address->country?->name ?? '',
            'zip' => $address->zip,
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
