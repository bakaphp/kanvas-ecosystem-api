<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class UsersRatingsTest extends TestCase
{
    use InventoryCases;

    public function testCreateUsersRatings()
    {
        $input = [
            'system_module_id' => 1,
            'entity_id' => 1,
            'rating' => 5.0,
            'comment' => 'Great',
        ];
        $this->graphQL(
            '
                mutation createUserRating($input: UsersRatingsInput!) {
                    createUserRating(input: $input) {
                        rating
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserRating' => [
                    'rating' => 5.0,
                ],
            ],
        ]);
    }

    public function testUpdateUsersRatings(): void
    {
        $input = [
            'system_module_id' => 1,
            'entity_id' => 1,
            'rating' => 5.0,
            'comment' => 'Great',
        ];
        $this->graphQL(
            '
                mutation createUserRating($input: UsersRatingsInput!) {
                    createUserRating(input: $input) {
                    id,
                        rating
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserRating' => [
                    'rating' => 5.0,
                ],
            ],
        ]);

        $input['rating'] = 4.0;

        $this->graphQL(
            '
                mutation createUserRating($input: UsersRatingsInput!) {
                    createUserRating(input: $input) {
                    id,
                        rating
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserRating' => [
                    'rating' => 4.0,
                ],
            ],
        ]);
    }

    public function testFilterUsersRatings(): void
    {
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'attributes' => [
                [
                    'name' => fake()->name,
                    'value' => fake()->name,
                ],
            ],
        ];

        $response = $this->createProduct($data);
        $productId = $response->json('data.createProduct.id');
        $systemModule = SystemModulesRepository::getByName(Products::class, app(Apps::class));
        $input = [
            'system_module_id' => $systemModule->getId(),
            'entity_id' => $productId,
            'rating' => 5.0,
            'comment' => 'Great',
        ];
        $this->graphQL(
            '
                mutation createUserRating($input: UsersRatingsInput!) {
                    createUserRating(input: $input) {
                    id,
                        rating
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createUserRating' => [
                    'rating' => 5.0,
                ],
            ],
        ]);

        $this->graphQL('
            query {
                products(
                    whereRating: {
                        column: RATING,
                        operator: EQ,
                        value: 5
                    }
                ) {
                    data {
                        name
                        description
                    }
                }
            }')
            ->assertJson([
                'data' => [
                    'products' => [
                        'data' => [
                            [
                                'name' => $data['name'],
                                'description' => $data['description'],
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
