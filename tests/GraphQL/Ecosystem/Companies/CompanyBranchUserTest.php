<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Tests\TestCase;

class CompanyBranchUserTest extends TestCase
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

    public function testAddUserToBranch()
    {
        $company = auth()->user()->getCurrentCompany();

        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful();

        $branch = $response->json('data.createCompanyBranch');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addUserToCompanyBranch($id: ID!, $user_id: ID!) {
                addUserToCompanyBranch(id: $id, user_id: $user_id)

            }',
            [
                'id' => $branch['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();
    }

    public function testCompanyBranchUser()
    {
        $company = auth()->user()->getCurrentCompany();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                companyBranchUsers(
                    first: 10
                ) {     
                    data {
            
                            id,
                            firstname,
                            displayname,
                        },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                    }
            }
            ',
            [

            ]
        )
        ->assertSuccessful();
    }

    public function testRemoveUserFromBranch()
    {
        $company = auth()->user()->getCurrentCompany();

        $branchData = $this->branchInputData($company->getId());

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation createCompanyBranch($input: CompanyBranchInput!) {
                createCompanyBranch(input: $input)
                {
                    id
                }
            }',
            [
                'input' => $branchData,
            ]
        )
        ->assertSuccessful();

        $branch = $response->json('data.createCompanyBranch');

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation addUserToCompanyBranch($id: ID!, $user_id: ID!) {
                addUserToCompanyBranch(id: $id, user_id: $user_id)

            }',
            [
                'id' => $branch['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation removeUserFromCompanyBranch($id: ID!, $user_id: ID!) {
                removeUserFromCompanyBranch(id: $id, user_id: $user_id)

            }',
            [
                'id' => $branch['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();
    }
}
