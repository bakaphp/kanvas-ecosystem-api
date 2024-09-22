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
}
