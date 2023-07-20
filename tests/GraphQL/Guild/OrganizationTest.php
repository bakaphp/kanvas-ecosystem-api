<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class OrganizationTest extends TestCase
{
    public function testGetOrganizations(): void
    {
        $this->graphQL('
            query {
                leads {
                    data {
                        id
                        uuid
                        name
                        address
                    }
                }
            }')->assertOk();
    }

    protected function createOrganizationAndGetResponse(array $input = [])
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $name = fake()->company();

        if (empty($input)) {
            $input = [
                'name' => $name,
                'address' => fake()->address(),
            ];
        }

        return $this->graphQL('
            mutation($input: OrganizationInput!) {
                createOrganization(input: $input) {                
                    id
                    name
                }
            }
        ', [
            'input' => $input,
        ])->json();
    }

    public function testOrganizationLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $name = fake()->company();

        $input = [
            'name' => $name,
            'address' => fake()->address(),
        ];

        $this->graphQL('
        mutation($input: OrganizationInput!) {
            createOrganization(input: $input) {                
                name
            }
        }
    ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createOrganization' => [
                    'name' => $name,
                ],
            ],
        ]);
    }

    public function testUpdateOrganization()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $name = fake()->company();

        $input = [
            'name' => $name,
            'address' => fake()->address(),
        ];

        $response = $this->createOrganizationAndGetResponse($input);

        $organizationId = $response['data']['createOrganization']['id'];

        $newName = fake()->company();

        $input = [
            'name' => $newName,
        ];

        $this->graphQL('
        mutation($id: ID!, $input: OrganizationInput!) {
            updateOrganization(id: $id, input: $input) {
                id
                name
            }
        }
    ', [
            'id' => $organizationId,
            'input' => $input,
        ])->assertJson([
            'data' => [
                'updateOrganization' => [
                    'id' => $organizationId,
                    'name' => $newName,

                ],
            ],
        ]);
    }

    public function testDeleteOrganization()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $name = fake()->company();

        $input = [
            'name' => $name,
            'address' => fake()->address(),
        ];

        $response = $this->createOrganizationAndGetResponse($input);

        $leadId = $response['data']['createOrganization']['id'];

        $this->graphQL('
        mutation($id: ID!) {
            deleteOrganization(id: $id)
        }
    ', [
            'id' => $leadId,
        ])->assertJson([
            'data' => [
                'deleteOrganization' => true,
            ],
        ]);
    }

    public function testRestoreOrganization()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $name = fake()->company();

        $input = [
            'name' => $name,
            'address' => fake()->address(),
        ];

        $response = $this->createOrganizationAndGetResponse($input);

        $leadId = $response['data']['createOrganization']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteOrganization(id: $id)
            }
        ', [
                'id' => $leadId,
            ])->assertJson([
                'data' => [
                    'deleteOrganization' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!) {
                restoreOrganization(id: $id)
            }
        ', [
                'id' => $leadId,
            ])->assertJson([
                'data' => [
                    'restoreOrganization' => true,
                ],
            ]);
    }
}
