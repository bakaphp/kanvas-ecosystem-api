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


        try {
            $response = $this->graphQL(
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
                    AppEnums::KANVAS_APP_KEY_HEADER->getValue() => trim($app->keys()->first()->client_secret_id),
                ]
            )->json();

            print_R($app->toArray()); 
            print_R($app->keys()->get()->toArray());
            print_r($response);
        } catch (\Exception $e) {
            print_R($e);

            throw $e;
        }
    }
}
