<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Tests\TestCase;

class CompanyAddressTest extends TestCase
{
    public function companyInputData(): array
    {
        return [
            'name' => fake()->company(),
            'website' => fake()->lastName(),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->email(),
            'zipcode' => 90120,
            'language' => 'en',
            'timezone' => 'UTC',
        ];
    }

    public function addressInputData(): array
    {
        return [
            'address' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip' => fake()->postcode(),
            'country_id' => 1,
            'city_id' => 1,
            'state_id' => 1,
            'is_default' => true,
        ];
    }

    public function testAddUserToCompany(): void
    {
        $companyData = $this->companyInputData();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    id
                }
            }',
            [
                'input' => $companyData,
            ]
        )
        ->assertSuccessful();

        $company = $response->json('data.createCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addAddressToCompany($id: ID!, $input: AddressInput!) {
                addAddressToCompany(id: $id, input: $input)

            }',
            [
                'id' => $company['id'],
                'input' => $this->addressInputData(),
            ]
        )
        ->assertSuccessful();
    }

    public function testGetCompanyAddresses()
    {
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    id  
                }
            }',
            [
                'input' => $this->companyInputData(),
            ]
        );

        $company = $response->json('data.createCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addAddressToCompany($id: ID!, $input: AddressInput!) {
                addAddressToCompany(id: $id, input: $input)

            }',
            [
                'id' => $company['id'],
                'input' => $this->addressInputData(),
            ]
        );

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                companyAddresses(first: 1) {     
                    data {
                        id
                        address
                        address_2
                        city
                        county
                        state
                        zip
                        is_default
                    },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                    }
            }
            ',
        );

        $response->assertJsonStructure([
            'data' => [
                'companyAddresses' => [
                    'data' => [
                        '*' => [
                            'id',
                            'address',
                            'address_2',
                            'city',
                            'county',
                            'state',
                            'zip',
                            'is_default',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testRemoveAddressFromCompany(): void
    {
        $companyData = $this->companyInputData();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    id
                }
            }',
            [
                'input' => $companyData,
            ]
        )
        ->assertSuccessful();

        $company = $response->json('data.createCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addAddressToCompany($id: ID!, $input: AddressInput!) {
                addAddressToCompany(id: $id, input: $input) {
                    id
                }

            }',
            [
                'id' => $company['id'],
                'input' => $this->addressInputData(),
            ]
        )
        ->assertSuccessful();

        $address = $response->json('data.addAddressToCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation removeAddressFromCompany($id: ID!, $address_id: ID!) {
                removeAddressFromCompany(id: $id, address_id: $address_id)

            }',
            [
                'id' => $company['id'],
                'address_id' => $address['id'],
            ]
        )
        ->assertSuccessful();
    }

    public function testUpdateAddressFromCompany(): void
    {
        $companyData = $this->companyInputData();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    id
                }
            }',
            [
                'input' => $companyData,
            ]
        );

        $company = $response->json('data.createCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addAddressToCompany($id: ID!, $input: AddressInput!) {
                addAddressToCompany(id: $id, input: $input) {
                    id
                }

            }',
            [
                'id' => $company['id'],
                'input' => $this->addressInputData(),
            ]
        );

        $address = $response->json('data.addAddressToCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateAddressFromCompany($id: ID!, $address_id: ID!, $input: AddressInput!) {
                updateAddressFromCompany(id: $id, address_id: $address_id, input: $input)

            }',
            [
                'id' => $company['id'],
                'address_id' => $address['id'],
                'input' => $this->addressInputData(),
            ]
        )
        ->assertSuccessful();
    }
}
