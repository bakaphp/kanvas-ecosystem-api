<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InventoryCases;

    public function addPaymentMethod(Companies $company, array $data): void
    {
        $response = $this->graphQL('
        mutation createPaymentMethod($input: PaymentMethodInput!) {
            createPaymentMethod(input: $input) {
                id
            }
        }
    ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);
    }

    public function getCardData(): array
    {
        return [
            "number" => "4111111111111111",
            "processor" => "portal",
            "brand" => "visa",
            "expiration_date" => "2030-12",
            "metadata" => [
                "data" => [
                    "test" => "test"
                ]
            ]
        ];
    }

    public function testCreatePaymentMethod()
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createPaymentMethod($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => $this->getCardData(),
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testListPaymentMethods()
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

        // Prepare input data for the order
        $this->addPaymentMethod($company, $this->getCardData());

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            query paymentMethods {
                paymentMethods {
                    id
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }
}
