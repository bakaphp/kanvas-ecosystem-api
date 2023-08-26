<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Tests\TestCase;

class CompanyTest extends TestCase
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

    public function testCreateCompany(): void
    {
        $companyData = $this->companyInputData();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    name,
                    website,
                    address,
                    zipcode,
                    email,
                    language
                }
            }',
            [
                'input' => $companyData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $companyData['name'])
        ->assertSee('website', $companyData['website'])
        ->assertSee('address', $companyData['address'])
        ->assertSee('zipcode', $companyData['zipcode'])
        ->assertSee('email', $companyData['email'])
        ->assertSee('language', $companyData['language']);
    }

    public function testUpdateCompany(): void
    {
        $companyData = $this->companyInputData();
        $company = auth()->user()->getCurrentCompany();
        $this->graphQL( /** @lang GraphQL */
            '
            mutation updateCompany($id: ID!, $input: CompanyInput!) {
                updateCompany(id: $id, input: $input)
                {
                    name,
                    website,
                    address,
                    zipcode,
                    email,
                    language
                }
            }',
            [
                'id' => $company->getId(),
                'input' => $companyData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $companyData['name'])
        ->assertSee('website', $companyData['website'])
        ->assertSee('address', $companyData['address'])
        ->assertSee('zipcode', $companyData['zipcode'])
        ->assertSee('email', $companyData['email'])
        ->assertSee('language', $companyData['language']);
    }

    public function testDeleteCompany(): void
    {
        $companyData = $this->companyInputData();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompany($input: CompanyInput!) {
                createCompany(input: $input)
                {
                    id,
                    name,
                    website,
                    address,
                    zipcode,
                    email,
                    language
                }
            }',
            [
                'input' => $companyData,
            ]
        )->json('data.createCompany');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation deleteCompany($id: ID!) {
                deleteCompany(id: $id)
            }',
            [
                'id' => $response['id']
            ]
        )
        ->assertSuccessful()
        ->assertSee('deleteCompany', true);
    }
}
