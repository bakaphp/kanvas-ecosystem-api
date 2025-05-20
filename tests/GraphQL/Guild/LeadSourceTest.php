<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\TestCase;

class LeadSourceTest extends TestCase
{
    public function testCreateLeadSource(): void
    {
        $companies = $this->graphQL('
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
            ');
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadType = $this->graphQL(
            '
                mutation createLeadType($input: LeadTypeInput!) {
                    createLeadType(input: $input){
                       uuid
                    }
                }
                ',
            [
                'input' => $input,
            ]
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $this->graphQL(
            '
                mutation createLeadSource($input: LeadSourceInput!) {
                    createLeadSource(input: $input){
                        name,
                        description,
                    }
                }
                ',
            [
                'input' => $input,
            ]
        )->assertJson(
            [
                'data' => [
                    'createLeadSource' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );
    }

    public function testUpdateLeadSource(): void
    {
        $companies = $this->graphQL(
            '
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
        '
        );
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadType = $this->graphQL(
            '
        mutation createLeadType($input: LeadTypeInput!) {
            createLeadType(input: $input){
               uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadSource = $this->graphQL(
            '
        mutation createLeadSource($input: LeadSourceInput!) {
            createLeadSource(input: $input){
                uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );

        $leadSource = json_decode($leadSource->decodeResponseJson()->json);
        $leadSourceId = $leadSource->data->createLeadSource->uuid;

        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];

        $this->graphQL(
            '
            mutation updateLeadSource($id: ID!, $input: LeadSourceInput!) {
                updateLeadSource(id: $id, input: $input){
                    name,
                    description,
                }
            }
            ',
            [
                'input' => $input,
                'id' => $leadSourceId,
            ]
        )->assertJson(
            [
                'data' => [
                    'updateLeadSource' => [
                        'name' => $input['name'],
                        'description' => $input['description'],
                    ],
                ],
            ]
        );
    }

    public function testDeleteLeadSource(): void
    {
        $companies = $this->graphQL(
            '
                query{
                    me {
                        companies {
                            id,
                            uuid
                        }
                    }
                }
        '
        );
        $companiesId = json_decode($companies->decodeResponseJson()->json);
        $companiesId = $companiesId->data->me->companies[0]->uuid;
        $input = [
            'companies_id' => $companiesId,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadType = $this->graphQL(
            '
        mutation createLeadType($input: LeadTypeInput!) {
            createLeadType(input: $input){
               uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );
        $leadType = json_decode($leadType->decodeResponseJson()->json);
        $leadType = $leadType->data->createLeadType->uuid;
        $input = [
            'companies_id' => $companiesId,
            'leads_types_id' => $leadType,
            'name' => fake()->name,
            'description' => fake()->text,
            'is_active' => fake()->boolean,
        ];
        $leadSource = $this->graphQL(
            '
        mutation createLeadSource($input: LeadSourceInput!) {
            createLeadSource(input: $input){
                uuid
            }
        }
        ',
            [
                'input' => $input,
            ]
        );

        $leadSource = json_decode($leadSource->decodeResponseJson()->json);
        $leadSourceId = $leadSource->data->createLeadSource->uuid;

        $this->graphQL(
            '
            mutation deleteLeadSource($id: ID!) {
                deleteLeadSource(id: $id)
            }
            ',
            [
                'id' => $leadSourceId,
            ]
        )->assertJson(
            [
                'data' => [
                    'deleteLeadSource' => true,
                ],
            ]
        );
    }

    public function testGetLeadSource(): void
    {
        $response = $this->graphQL(
            '
                {
                    leadSources {
                        data {
                            id,
                            name,
                            description,
                            is_active,
                            leadType {
                                id
                            }
                        }
                    }
                }   
            '
        )->assertJsonStructure([
            'data' => [
                'leadSources' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'is_active',
                            'leadType' => [
                                'id',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testNotDeletedLeadSourceInUse(): void
    {
        $leadRotation = LeadRotation::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Lead Rotation',
            'hits' => 1,
            'leads_rotations_email' => '',
        ]);
        $leadType = LeadType::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Lead Type',
            'description' => 'Lead Type Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);
        $leadSource = LeadSource::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Lead Source',
            'description' => 'Lead Source Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
            'leads_types_id' => $leadType->getId(),
        ]);
        $input = [
            'name' => fake()->word,
            'agents_id' => auth()->user()->getId(),
            'is_default' => true,
            'rotations_id' => $leadRotation->getId(),
            'source_name' => 'source',
            'lead_sources_id' => $leadSource->getId(),
            'lead_types_id' => $leadType->getId(),
            'template' => 'template',
        ];
        $this->graphQL(
            'mutation createLeadReceiver($input: LeadReceiverInput!) {
                createLeadReceiver(input: $input){
                    name,
                    agent{
                        id
                    },
                    is_default,
                    source_name,
                    leadSource{
                        id
                    },
                    leadType{
                        id
                    },
                    template
                }
            }',
            [
                'input' => $input,
            ]
        );
        $response = $this->graphQL('
            mutation deleteLeadSource($id: ID!){
                deleteLeadSource(id: $id)
            }
        ', [
            'id' => $leadSource->uuid,
        ]);
        $response = $response->json();
        $this->assertNotEmpty($response['errors']);
    }
}
