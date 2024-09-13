<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
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

    public function testGetCompanies(): void
    {
        $this->graphQL( /** @lang GraphQL */
            '
            {
                companies(first: 10) {     
                    data {
            
                            id,
                            name,
                            website,
                            address,
                            zipcode,
                            email,
                            language,
                            timezone,
                            phone,
                            country_code,
                            created_at,
                            updated_at
                        },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                    }
            }
            
            '
        )
        ->assertSuccessful()
        ->assertSee('name');
    }

    public function testGetCompanySettings()
    {
        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                companySettings {  
                    name,
                    settings
                }
            }
            
            
            '
        )
        ->assertSuccessful()
        ->assertSee('name');
    }

    public function testGetAdminCompanySettings()
    {
        $usr = auth()->user();
        $company = $usr->getCurrentCompany();
        $app = app(Apps::class);

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                adminCompanySettings(entity_uuid: "' . $company->uuid . '") {  
                    key,
                    value,
                    public
                }
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
        ->assertSuccessful()
        ->assertSee('key')
        ->assertSee('value')
        ->assertSee('public');
    }

    public function testGetAdminCompanySetting()
    {
        $usr = auth()->user();
        $company = $usr->getCurrentCompany();
        $app = app(Apps::class);
        $key = 'testName';
        $company->set($key, 'testValue');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                adminCompanySetting(entity_uuid: "' . $company->uuid . '", key: "' . $key . '") 
            }
            ',
            [],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        )
        ->assertSuccessful()
        ->assertSee('testValue');
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
                'id' => $response['id'],
            ]
        )
        ->assertSuccessful()
        ->assertSee('deleteCompany', true);
    }
}
