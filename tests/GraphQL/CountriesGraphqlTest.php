<?php
declare(strict_types=1);
namespace Tests\GraphQL;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesApplication;
use Kanvas\Locations\Countries\Models\Countries;

class CountriesGraphqlTest extends BaseTestCase
{
    use CreatesApplication;
    use MakesGraphQLRequests;

    /**
     * test_save
     *
     * @return void
     */
    public function test_save() : void
    {
        $name = fake()->name;
        $stateName = fake()->name;
        $cityName = fake()->name;
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
        ', [
            'name' => $name,
            'stateName' => $stateName,
            'cityName' => $cityName,
        ])->assertJson([
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
     * test_get
     *
     * @return void
     */
    public function test_get(): void
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
     * test_update
     *
     * @return void
     */
    public function test_update(): void
    {
        $country = Countries::orderBy('id', 'desc')->first();
        $name = fake()->name;
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation(
                $id: Int!
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
        ', [
            'id' => $country->id,
            'name' => $name,
            'code' => $country->code,
            'flag' => $country->flag,
        ]);
        $this->assertArrayHasKey('data', $response);
    }
}
