<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

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
        ];
    }

    public function testCreateCompanyBranch()
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
}
