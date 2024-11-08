<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\TestCase;

class OrderTest extends TestCase
{
    public function testCreateDraftOrder()
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

        // Prepare input data for the draft order
        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => hash('sha256', random_bytes(10)),
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'items' => [
                [
                    'variant_id' => $variantWarehouse->variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }

    public function testReturnOrderFromCart()
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

        // Prepare input data for the order
        $data = [
            'CreditCardInput' => [
                'name' => fake()->name(),
                'number' => fake()->creditCardNumber(null, false, ''),
                'exp_month' => 12,
                'exp_year' => 2026,
            ],
            'CreditCardBillingInput' => [
                'address' => fake()->address(),
                'address2' => fake()->address(),
                'city' => fake()->city(),
                'state' => 'MT',
                'zip' => 59068,
                'country' => 'US',
            ],
            'items' => [
                [
                    'variant_id' => $variantWarehouse->variant->getId(),
                    'quantity' => 2,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
    }
}
