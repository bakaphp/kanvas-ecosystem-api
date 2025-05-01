<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Kanvas\Companies\Models\CompaniesAddress;
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
            'fullname' => fake()->name(),
            'phone' => fake()->phoneNumber(),
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

    public function addAddress($companyId, $input)
    {
        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addAddressToCompany($id: ID!, $input: CompanyAddressInput!) {
                addAddressToCompany(id: $id, input: $input) {
                    id
                    address
                }

            }',
            [
                'id' => $companyId,
                'input' => $input,
            ]
        );

        return $response->json('data.addAddressToCompany');
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

        $response = $this->addAddress($company['id'], $this->addressInputData());

        $this->assertNotNull($response);
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

        $address = $this->addAddress($company['id'], $this->addressInputData());

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

        $address = $this->addAddress($company['id'], $this->addressInputData());

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

    public function testAddAddressToCompanyWithIsDefault(): void
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

        $addressData1 = $this->addAddress($company['id'], [
            ...$this->addressInputData(),
            'is_default' => true,
        ]);

        $firstAddress = CompaniesAddress::find($addressData1['id']);
        $isFirstAddressDefault = $firstAddress->is_default;

        $addressData2 = $this->addAddress($company['id'], [
            ...$this->addressInputData(),
            'is_default' => true,
        ]);

        $secondAddress = CompaniesAddress::find($addressData2['id']);

        $this->assertTrue((bool) $isFirstAddressDefault);
        $this->assertTrue((bool) $secondAddress->is_default);
        $this->assertFalse((bool) $firstAddress->refresh()->is_default);
    }

    public function testAddAddressToCompanyKeepOldDefault(): void
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

        $addressData1 = $this->addAddress($company['id'], [
            ...$this->addressInputData(),
            'is_default' => true,
        ]);

        $firstAddress = CompaniesAddress::find($addressData1['id']);
        $isFirstAddressDefault = $firstAddress->is_default;

        $addressData2 = $this->addAddress($company['id'], [
            ...$this->addressInputData(),
            'is_default' => false,
        ]);

        $secondAddress = CompaniesAddress::find($addressData2['id']);

        $this->assertTrue((bool) $isFirstAddressDefault);
        $this->assertFalse((bool) $secondAddress->is_default);
        $this->assertTrue((bool) $firstAddress->refresh()->is_default);
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

        $addressData = $this->addAddress($company['id'], $this->addressInputData());
        $address = CompaniesAddress::find($addressData['id']);


        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateCompanyAddress($id: ID!, $address_id: ID!, $input: CompanyAddressInput!) {
                updateCompanyAddress(id: $id, address_id: $address_id, input: $input) {
                    id
                }

            }',
            [
                'id' => $company['id'],
                'address_id' => $addressData['id'],
                'input' => [
                    "fullname" => "John Doe",
                    "phone" => "1234567890",
                    "address" => $addressData['address'],
                ],
            ]
        );

        $this->assertEquals("John Doe", $address->refresh()->fullname);
        $this->assertEquals("1234567890", $address->refresh()->phone);
    }
}
