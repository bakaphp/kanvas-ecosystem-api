<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Tests\TestCase;

class CountriesGraphqlTest extends TestCase
{
    /**
     * test_save.
     */
    public function testCreate(): void
    {
        $name = fake()->name;
        $stateName = fake()->name;
        $cityName = fake()->name;
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $name: String!
                $stateName: String!
                $cityName: String!
            ) {
                createCountry(
                    name : $name
                    code: "DO",
                    flag: "DO",
                    states: [
                        {
                            name: $stateName,
                            code: "LA",
                            cities: [
                               { name: $cityName }
                            ]
                        }
                    ]
                ) {
                    id
                    name
                    code
                    flag
                }
            }
        ',
            [
            'name' => $name,
            'stateName' => $stateName,
            'cityName' => $cityName,
        ],
            [],
            [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]
        )->assertJson([
            'data' => [
                'createCountry' => [
                    'name' => $name,
                    'code' => 'DO',
                    'flag' => 'DO',
                ],
            ],
        ]);
    }

    /**
     * test_get.
     */
    public function testGet(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query{
                countries(first: 5, page: 1, orderBy: [{ column: ID, order: DESC }]) {
                data {

                            id,
                            name,
                            states {
                                id,
                                name,
                                cities {
                                    name
                                }
                            }
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }

            }
            ');
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * test_update.
     */
    public function testUpdate(): void
    {
        $country = Countries::orderBy('id', 'desc')->first();
        $name = fake()->name;
        $app = app(Apps::class);
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);


        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $id: ID!
                $name: String!,
                $code: String!,
                $flag: String!
            ) {
                updateCountry(
                    id: $id
                    name: $name,
                    code: $code,
                    flag: $flag
                ) {
                    id
                    name
                }
            }
        ',
            [
            'id' => $country->id,
            'name' => $name,
            'code' => $country->code,
            'flag' => $country->flag,
        ],
            [],
            [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]
        );
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * test_where.
     *
     * @return void
     */
    public function testFilter()
    {
        $country = Countries::orderBy('id', 'desc')->first();
        $response = $this->graphQL(/** @lang GraphQL */ '
            query COUNTRIES($name: Mixed) {
                countries(
                    first: 50,
                    page: 1,
                    orderBy: [{ column: ID, order: ASC }]
                    where: {
                        column:NAME, operator: EQ , value: $name
                    },
                ) {
                data {

                        id,
                        name
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }

            }', [
            'name' => "$country->name",
        ])->assertJson([
            'data' => [
                'countries' => [
                    'data' => [
                        [
                            'id' => $country->id,
                            'name' => $country->name,
                        ],
                    ],
                    'paginatorInfo' => [
                        'currentPage' => 1,
                        'lastPage' => 1,
                    ],
                ],
            ],
        ]);
    }

    public function testHasState()
    {
        $country = Countries::orderBy('id', 'desc')->first();
        $state = States::first();
        $response = $this->graphQL(/** @lang GraphQL */ '
            query COUNTRIES($countryId: Mixed! $stateName: Mixed!) {
                countries(
                    first: 50,
                    page: 1,
                    orderBy: [{ column: ID, order: ASC }]
                    where: {
                        column:ID, operator: EQ , value: $countryId
                    },
                    hasStates: {
                        column:NAME, operator: EQ, value: $stateName
                    },
                ) {
                data {

                        id,
                        name,
                        states {
                            id,
                            name
                        }
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }

            }', [
            'countryId' => $state->countries_id,
            'stateName' => $state->name,
        ])->assertJson([
            'data' => [
                'countries' => [
                    'data' => [
                        [
                            'id' => $state->countries_id,
                            'name' => $state->country->name,
                            'states' => [
                                [
                                    'id' => $state->id,
                                    'name' => $state->name,
                                ],
                            ],
                        ],
                    ],
                    'paginatorInfo' => [
                        'currentPage' => 1,
                        'lastPage' => 1,
                    ],
                ],
            ],
        ]);
    }
}
