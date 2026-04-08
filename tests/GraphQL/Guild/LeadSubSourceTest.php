<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\LeadSource;
use Tests\TestCase;

class LeadSubSourceTest extends TestCase
{
    protected function createLeadSource(): LeadSource
    {
        return LeadSource::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Test Source ' . fake()->word(),
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);
    }

    public function testCreateLeadSubSource(): void
    {
        $source = $this->createLeadSource();

        $input = [
            'leads_sources_id' => $source->getId(),
            'name' => fake()->word(),
        ];

        $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                    uuid
                    name
                    source {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createLeadSubSource' => [
                    'name' => $input['name'],
                    'source' => ['id' => (string) $source->getId()],
                ],
            ],
        ]);
    }

    public function testUpdateLeadSubSource(): void
    {
        $source = $this->createLeadSource();

        $created = $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'leads_sources_id' => $source->getId(),
                'name' => fake()->word(),
            ],
        ])->assertSuccessful();

        $id = $created->json('data.createLeadSubSource.id');
        $newName = fake()->word() . '-updated';

        $this->graphQL('
            mutation($id: ID!, $input: UpdateLeadSubSourceInput!) {
                updateLeadSubSource(id: $id, input: $input) {
                    id
                    name
                }
            }
        ', [
            'id' => $id,
            'input' => ['name' => $newName],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateLeadSubSource' => [
                    'id' => $id,
                    'name' => $newName,
                ],
            ],
        ]);
    }

    public function testDeleteLeadSubSource(): void
    {
        $source = $this->createLeadSource();

        $created = $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'leads_sources_id' => $source->getId(),
                'name' => fake()->word(),
            ],
        ])->assertSuccessful();

        $id = $created->json('data.createLeadSubSource.id');

        $this->graphQL('
            mutation($id: ID!) {
                deleteLeadSubSource(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson([
            'data' => ['deleteLeadSubSource' => true],
        ]);
    }

    public function testGetSubSources(): void
    {
        $source = $this->createLeadSource();

        $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'leads_sources_id' => $source->getId(),
                'name' => fake()->word(),
            ],
        ])->assertSuccessful();

        $this->graphQL('
            query {
                subSources {
                    data {
                        id
                        uuid
                        name
                        source {
                            id
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'subSources' => [
                    'data' => [
                        '*' => ['id', 'uuid', 'name'],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateLeadWithSubSource(): void
    {
        $source = $this->createLeadSource();

        $subSource = $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'leads_sources_id' => $source->getId(),
                'name' => fake()->word(),
            ],
        ])->assertSuccessful();

        $subSourceId = $subSource->json('data.createLeadSubSource.id');

        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    id
                    subSource {
                        id
                        name
                    }
                }
            }
        ', [
            'input' => [
                'branch_id' => $branch->getId(),
                'title' => fake()->sentence(),
                'pipeline_stage_id' => 0,
                'source_id' => $source->getId(),
                'sub_source_id' => $subSourceId,
                'people' => [
                    'firstname' => fake()->firstName(),
                    'lastname' => fake()->lastName(),
                    'contacts' => [
                        [
                            'value' => fake()->email(),
                            'contacts_types_id' => 1,
                            'weight' => 0,
                        ],
                    ],
                    'address' => [],
                ],
                'custom_fields' => [],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createLead' => [
                    'subSource' => ['id' => $subSourceId],
                ],
            ],
        ]);
    }

    public function testUpdateLeadSubSource_OnLead(): void
    {
        $source = $this->createLeadSource();

        $subSource = $this->graphQL('
            mutation($input: LeadSubSourceInput!) {
                createLeadSubSource(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'leads_sources_id' => $source->getId(),
                'name' => fake()->word(),
            ],
        ])->assertSuccessful();

        $subSourceId = $subSource->json('data.createLeadSubSource.id');

        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $people = [
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'contacts' => [
                [
                    'value' => fake()->email(),
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
            ],
            'address' => [],
        ];

        $lead = $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    id
                    people { id }
                }
            }
        ', [
            'input' => [
                'branch_id' => $branch->getId(),
                'title' => fake()->sentence(),
                'pipeline_stage_id' => 0,
                'people' => $people,
                'custom_fields' => [],
            ],
        ])->assertSuccessful();

        $leadId = $lead->json('data.createLead.id');
        $peopleId = $lead->json('data.createLead.people.id');

        $this->graphQL('
            mutation($id: ID!, $input: LeadUpdateInput!) {
                updateLead(id: $id, input: $input) {
                    id
                    subSource {
                        id
                    }
                }
            }
        ', [
            'id' => $leadId,
            'input' => [
                'branch_id' => $branch->getId(),
                'title' => fake()->sentence(),
                'people_id' => $peopleId,
                'sub_source_id' => $subSourceId,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateLead' => [
                    'subSource' => ['id' => $subSourceId],
                ],
            ],
        ]);
    }
}
