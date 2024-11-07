<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompanyBranchTest extends TestCase
{
    public function branchInputData(int $companyId): array
    {
        return [
            'name' => fake()->company(),
            'is_default' => false,
            'companies_id' => $companyId,
            'phone' => fake()->phoneNumber(),
            'email' => fake()->email(),
            'country_code' => 'US',
            'is_active' => fake()->boolean(),
            'countries_id' => 1,
            'states_id' => 1,
            'cities_id' => 1,
            'address_2' => fake()->address(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'zip' => fake()->postcode(),
        ];
    }

    public function testCreateCompanyBranch()
    {
        $company = auth()->user()->getCurrentCompany();
        $userCompanyBranchCount = auth()->user()->branches()->count();
        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                    name,
                    is_default,
                    companies_id,
                    email,
                    phone
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $branchData['name'])
        ->assertSee('email', $branchData['email'])
        ->assertSee('phone', $branchData['phone'])
        ->assertSee('address', $branchData['address'])
        ->assertSee('address_2', $branchData['address_2'])
        ->assertSee('city', $branchData['city'])
        ->assertSee('state', $branchData['state'])
        ->assertSee('country', $branchData['country'])
        ->assertSee('zip', $branchData['zip']);

        $this->assertEquals($userCompanyBranchCount + 1, auth()->user()->branches()->count());
    }

    public function testUpdateCompanyBranch()
    {
        $company = auth()->user()->getCurrentCompany();
        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                    name,
                    is_default,
                    companies_id,
                    email,
                    phone
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $branchData['name'])
        ->assertSee('email', $branchData['email'])
        ->assertSee('phone', $branchData['phone']);

        $branch = $response->json('data.createCompanyBranch');

        $branchData['name'] = 'Updated Branch Name';

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation updateCompanyBranch($id: ID!, $input: CompanyBranchInput!) {
                updateCompanyBranch(id: $id, input: $input)
                {
                    id
                    name,
                    is_default,
                    companies_id,
                    email,
                    phone
                }
            }',
            [
                'id' => $branch['id'],
                'input' => $branchData,
            ]
        )
            ->assertSuccessful()
            ->assertSee('name', $branchData['name']);
    }

    public function testGetBranches()
    {
        $company = auth()->user()->getCurrentCompany();
        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                    name,
                    is_default,
                    companies_id,
                    email,
                    phone
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $branchData['name'])
        ->assertSee('email', $branchData['email'])
        ->assertSee('phone', $branchData['phone']);

        $branch = $response->json('data.createCompanyBranch');

        $response = $this->graphQL( /** @lang GraphQL */
            '{
            branches(first: 10) {     
                data {
        
                        id,
                        name,
                    },
                    paginatorInfo {
                      currentPage
                      lastPage
                    }
                }
            }'
        )
            ->assertSuccessful()
            ->assertSee('name', $branchData['name']);
    }

    public function testDeleteCompanyBranch()
    {
        $company = auth()->user()->getCurrentCompany();
        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                    name,
                    is_default,
                    companies_id,
                    email,
                    phone
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful()
        ->assertSee('name', $branchData['name'])
        ->assertSee('email', $branchData['email'])
        ->assertSee('phone', $branchData['phone']);

        $branch = $response->json('data.createCompanyBranch');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation deleteCompanyBranch($id: ID!) {
                deleteCompanyBranch(id: $id)
            }',
            [
                'id' => $branch['id'],
            ]
        )
            ->assertSuccessful()
            ->assertSee('true');
    }

    public function testUploadFileToCompanyBranch()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $operations = [
            'query' => /** @lang GraphQL */ '
            mutation uploadFileToCompanyBranch($id: ID!, $file: Upload!) {
                uploadFileToCompanyBranch(id: $id, file: $file)
                    { 
                        id
                        name
                        files{
                            data {
                                name
                                url
                            }
                        }
                    } 
                }
            ',
            'variables' => [
                'id' => $company->branch->getId(),
                'file' => null,
            ],
        ];

        $map = [
            '0' => ['variables.file'],
        ];

        $file = [
            '0' => UploadedFile::fake()->create('branch.jpg'),
        ];

        $this->multipartGraphQL($operations, $map, $file)->assertSee('id')
            ->assertSee('name')
            ->assertSee('files')
            ->assertSee('branch.jpg');
    }
}
