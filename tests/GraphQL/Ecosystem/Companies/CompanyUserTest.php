<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Tests\TestCase;

class CompanyUserTest extends TestCase
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
            mutation addUserToCompany($id: ID!, $user_id: ID!) {
                addUserToCompany(id: $id, user_id: $user_id)

            }',
            [
                'id' => $company['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();
    }

    public function testGetCompanyUsers()
    {
        $company = auth()->user()->getCurrentCompany();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            {
                companyUsers(
                    orderBy: [{ field: "id", order: DESC }]
                ) {     
                    data {
            
                            id,
                            displayname,
                            email
                        },
                        paginatorInfo {
                          currentPage
                          lastPage
                        }
                    }
            }
            ',
        )
        ->assertSuccessful();
    }

    public function testRemoveUserFromCompany(): void
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
            mutation addUserToCompany($id: ID!, $user_id: ID!) {
                addUserToCompany(id: $id, user_id: $user_id)

            }',
            [
                'id' => $company['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();

        $response = $this->graphQL( /** @lang GraphQL */
            '
            mutation removeUserFromCompany($id: ID!, $user_id: ID!) {
                removeUserFromCompany(id: $id, user_id: $user_id)

            }',
            [
                'id' => $company['id'],
                'user_id' => auth()->user()->getId(),
            ]
        )
        ->assertSuccessful();
    }
}
