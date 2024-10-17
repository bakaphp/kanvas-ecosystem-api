<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\TestCase;

class AuthorizeTest extends TestCase
{
    public function testCreateCreditCardOrder()
    {
        // Prepare input data for the draft order
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
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrder($input: OrderInput!) {
                createOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ]);

        $response->assertSuccessful();
    }
}
