<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
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

    public function testCreateOrganizationStartsWithZeroEmployees(): void
    {
        $response = $this->createOrganizationAndGetResponse();
        $organizationId = (int) $response['data']['createOrganization']['id'];

        $this->assertSame(0, Organization::find($organizationId)->total_employees);
    }

    public function testAddPeopleToOrganizationIncrementsTotalEmployees(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $response = $this->createOrganizationAndGetResponse();
        $organizationId = (int) $response['data']['createOrganization']['id'];

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->count(2)
            ->create();

        $this->graphQL('
            mutation($input: OrganizationPeopleInput!) {
                addPeopleToOrganization(input: $input)
            }
        ', [
            'input' => [
                'organization_id' => $organizationId,
                'peoples_id' => $people->pluck('id')->map(fn ($id) => (string) $id)->all(),
            ],
        ])->assertJson([
            'data' => ['addPeopleToOrganization' => true],
        ]);

        $this->assertSame(2, Organization::find($organizationId)->total_employees);

        $this->graphQL('
            query($id: Mixed!) {
                organizations(where: { column: ID, operator: EQ, value: $id }) {
                    data { id total_employees }
                }
            }
        ', ['id' => $organizationId])
            ->assertSuccessful()
            ->assertJsonPath('data.organizations.data.0.total_employees', 2);
    }

    public function testRemovePeopleFromOrganizationDecrementsTotalEmployees(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $response = $this->createOrganizationAndGetResponse();
        $organizationId = (int) $response['data']['createOrganization']['id'];

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->count(3)
            ->create();

        $this->graphQL('
            mutation($input: OrganizationPeopleInput!) {
                addPeopleToOrganization(input: $input)
            }
        ', [
            'input' => [
                'organization_id' => $organizationId,
                'peoples_id' => $people->pluck('id')->map(fn ($id) => (string) $id)->all(),
            ],
        ])->assertSuccessful();

        $this->assertSame(3, Organization::find($organizationId)->total_employees);

        $toRemove = $people->take(2)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->graphQL('
            mutation($input: OrganizationPeopleInput!) {
                removePeopleFromOrganization(input: $input)
            }
        ', [
            'input' => [
                'organization_id' => $organizationId,
                'peoples_id' => $toRemove,
            ],
        ])->assertJson([
            'data' => ['removePeopleFromOrganization' => true],
        ]);

        $this->assertSame(1, Organization::find($organizationId)->total_employees);
    }

    public function testOrganizationsQuerySupportsSearch(): void
    {
        $uniqueName = 'OrgSearch-' . fake()->unique()->word() . '-' . time();
        $this->createOrganizationAndGetResponse([
            'name' => $uniqueName,
            'address' => fake()->address(),
        ]);

        $this->graphQL('
            query($search: String) {
                organizations(search: $search) {
                    data { id name total_employees }
                }
            }
        ', ['search' => $uniqueName])
            ->assertSuccessful()
            ->assertJsonPath('data.organizations.data.0.name', $uniqueName);
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
