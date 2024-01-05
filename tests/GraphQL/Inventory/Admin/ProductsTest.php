<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory\Admin;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    /**
     * test get product.
     */
    public function testGetProduct(): void
    {
        $app = app(Apps::class);

        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];

        $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data],
        ])->assertOk();

        $this->graphQL(
            '
            query {
                products {
                    data {
                        name
                        description
                    }
                }
            }',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )->assertOk();
    }
}
