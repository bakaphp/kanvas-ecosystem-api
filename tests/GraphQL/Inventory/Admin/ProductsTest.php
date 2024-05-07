<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory\Admin;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\AccessControlList\Enums\RolesEnums;
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

        //cant figure out why the user doesn\'t exist for the key
        try {
            $app->keys()->firstOrFail()->user()->firstOrFail();
        } catch(ModelNotFoundException $e) {
            $user = auth()->user();
            $app->keys()->firstOrFail()->updateOrFail([
                'users_id' => $user->getId(),
            ]);
            $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);
        }

        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
            'description' => fake()->text,
        ];

        $response = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data)
                {
                    name
                    description
                }
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
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
