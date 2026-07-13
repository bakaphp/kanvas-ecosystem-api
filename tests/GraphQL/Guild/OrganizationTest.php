<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;
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
        // Suffix-free name so OrganizationNameNormalizerService leaves it untouched.
        $name = 'Org-' . fake()->unique()->word() . '-' . time();

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

        // Suffix-free name so OrganizationNameNormalizerService leaves it untouched.
        $newName = 'Org-' . fake()->unique()->word() . '-' . time();

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

    public function testCreateOrganizationNormalizesAndSavesPhone(): void
    {
        // Suffix-free name: fake()->company() randomly appends a legal suffix (LLC,
        // Inc, ...) that OrganizationNameNormalizerService strips on save, which would
        // make the name assertion flaky. This test only cares about phone normalization.
        $name = 'OrgPhone-' . fake()->unique()->word() . '-' . time();
        $digits = fake()->unique()->numerify('1##########');

        $response = $this->graphQL('
            mutation($input: OrganizationInput!) {
                createOrganization(input: $input) {
                    id
                    name
                    email
                    phone
                }
            }
        ', [
            'input' => [
                'name' => $name,
                'email' => 'org-' . fake()->unique()->safeEmail(),
                // Formatted on the way in — must be stored as bare digits, like People contacts.
                'phone' => '+' . substr($digits, 0, 1) . ' (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7),
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.createOrganization.name', $name)
        ->assertJsonPath('data.createOrganization.phone', $digits);

        $organizationId = (int) $response->json('data.createOrganization.id');
        $this->assertSame($digits, Organization::find($organizationId)->phone);
    }

    public function testUpdateOrganizationNormalizesAndSavesPhone(): void
    {
        $response = $this->createOrganizationAndGetResponse();
        $organizationId = $response['data']['createOrganization']['id'];
        $digits = fake()->unique()->numerify('1##########');

        $this->graphQL('
            mutation($id: ID!, $input: OrganizationInput!) {
                updateOrganization(id: $id, input: $input) {
                    id
                    phone
                }
            }
        ', [
            'id' => $organizationId,
            'input' => [
                'name' => fake()->company(),
                'phone' => '(' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7),
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.updateOrganization.phone', substr($digits, 1));

        $this->assertSame(substr($digits, 1), Organization::find((int) $organizationId)->phone);
    }

    public function testFilterOrganizationsByPhone(): void
    {
        $digits = fake()->unique()->numerify('1##########');

        $this->createOrganizationAndGetResponse([
            'name' => fake()->company(),
            'phone' => '+' . $digits,
        ]);

        $this->graphQL('
            query($where: QueryOrganizationsWhereWhereConditions) {
                organizations(where: $where) {
                    data {
                        id
                        phone
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'PHONE',
                'operator' => 'EQ',
                'value' => $digits,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.organizations.data.0.phone', $digits);
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

    protected function createOrganizationTypeAndGetId(): string
    {
        $response = $this->graphQL('
            mutation($input: OrganizationTypeInput!) {
                createOrganizationType(input: $input) {
                    id
                    name
                    slug
                    is_active
                }
            }
        ', [
            'input' => [
                'name' => fake()->word() . '-' . fake()->numberBetween(1, 9999),
                'description' => fake()->sentence(),
                'is_active' => true,
                'is_default' => false,
            ],
        ]);

        $response->assertSuccessful();

        return $response->json('data.createOrganizationType.id');
    }

    public function testCreateOrganizationType(): void
    {
        $name = fake()->word() . '-' . fake()->numberBetween(1, 9999);

        $this->graphQL('
            mutation($input: OrganizationTypeInput!) {
                createOrganizationType(input: $input) {
                    id
                    name
                    slug
                    is_active
                    is_default
                }
            }
        ', [
            'input' => [
                'name' => $name,
                'description' => fake()->sentence(),
                'is_active' => true,
                'is_default' => false,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.createOrganizationType.name', $name)
        ->assertJsonPath('data.createOrganizationType.is_active', true);
    }

    public function testUpdateOrganizationType(): void
    {
        $id = $this->createOrganizationTypeAndGetId();
        $newName = fake()->word() . '-updated-' . fake()->numberBetween(1, 9999);

        $this->graphQL('
            mutation($id: ID!, $input: UpdateOrganizationTypeInput!) {
                updateOrganizationType(id: $id, input: $input) {
                    id
                    name
                    is_active
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'name' => $newName,
                'is_active' => false,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.updateOrganizationType.id', $id)
        ->assertJsonPath('data.updateOrganizationType.name', $newName)
        ->assertJsonPath('data.updateOrganizationType.is_active', false);
    }

    public function testDeleteOrganizationType(): void
    {
        $id = $this->createOrganizationTypeAndGetId();

        $this->graphQL('
            mutation($id: ID!) {
                deleteOrganizationType(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteOrganizationType' => true]]);

        $this->assertSame(1, OrganizationType::find((int) $id)->is_deleted);
    }

    public function testListOrganizationTypes(): void
    {
        $this->createOrganizationTypeAndGetId();

        $this->graphQL('
            query {
                organizationTypes {
                    data {
                        id
                        name
                        slug
                        is_active
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'organizationTypes' => [
                    'data' => [['id', 'name', 'slug', 'is_active']],
                ],
            ],
        ]);
    }

    public function testCreateOrganizationWithType(): void
    {
        $typeId = $this->createOrganizationTypeAndGetId();
        $name = fake()->company();

        $this->graphQL('
            mutation($input: OrganizationInput!) {
                createOrganization(input: $input) {
                    id
                    name
                    organization_type {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'name' => $name,
                'organization_type_id' => $typeId,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.createOrganization.name', $name)
        ->assertJsonPath('data.createOrganization.organization_type.id', $typeId);
    }

    public function testUpdateOrganizationAssignsType(): void
    {
        $response = $this->createOrganizationAndGetResponse();
        $organizationId = $response['data']['createOrganization']['id'];
        $typeId = $this->createOrganizationTypeAndGetId();

        $this->graphQL('
            mutation($id: ID!, $input: OrganizationInput!) {
                updateOrganization(id: $id, input: $input) {
                    id
                    organization_type {
                        id
                    }
                }
            }
        ', [
            'id' => $organizationId,
            'input' => [
                'name' => fake()->company(),
                'organization_type_id' => $typeId,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.updateOrganization.organization_type.id', $typeId);
    }

    public function testFilterOrganizationsByType(): void
    {
        $typeId = $this->createOrganizationTypeAndGetId();
        $name = fake()->company();

        $this->createOrganizationAndGetResponse([
            'name' => $name,
            'organization_type_id' => $typeId,
        ]);

        $this->graphQL('
            query($where: QueryOrganizationsWhereWhereConditions) {
                organizations(where: $where) {
                    data {
                        id
                        organization_type {
                            id
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ORGANIZATION_TYPE_ID',
                'operator' => 'EQ',
                'value' => (int) $typeId,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.organizations.data.0.organization_type.id', $typeId);
    }

    public function testOrganizationLeadsRelation(): void
    {
        $response = $this->createOrganizationAndGetResponse();
        $organizationId = (int) $response['data']['createOrganization']['id'];

        $branch = auth()->user()->getCurrentBranch();

        $leadResponse = $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'branch_id' => $branch->getId(),
                'title' => fake()->sentence(),
                'pipeline_stage_id' => 0,
                'people' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'contacts' => [
                        ['value' => fake()->email(), 'contacts_types_id' => 1, 'weight' => 0],
                    ],
                    'address' => [],
                ],
                'custom_fields' => [],
            ],
        ]);

        $leadResponse->assertSuccessful();
        $leadId = (int) $leadResponse->json('data.createLead.id');

        Lead::find($leadId)->update(['organization_id' => $organizationId]);

        $this->graphQL('
            query($id: Mixed!) {
                organizations(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        leads {
                            data { id }
                        }
                    }
                }
            }
        ', ['id' => $organizationId])
        ->assertSuccessful()
        ->assertJsonPath('data.organizations.data.0.leads.data.0.id', (string) $leadId);
    }
}
