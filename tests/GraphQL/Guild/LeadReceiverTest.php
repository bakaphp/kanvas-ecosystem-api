<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Models\LeadRotation;

class LeadReceiverTest extends TestCase
{
    public function testCreateLeadReceiver(): void
    {
        $leadRotation = LeadRotation::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Lead Rotation',
            'hits' => 1,
            'leads_rotations_email' => ''
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
        )->assertJson([
            'data' => [
                'createLeadReceiver' => [
                    'name' => $input['name'],
                    'agent' => [
                        'id' => $input['agents_id'],
                    ],
                    'is_default' => $input['is_default'],
                    'source_name' => $input['source_name'],
                    'leadSource' => [
                        'id' => $input['lead_sources_id'],
                    ],
                    'leadType' => [
                        'id' => $input['lead_types_id'],
                    ],
                    'template' => 'template'
                ],
            ],
        ]);
    }

    public function testUpdateLeadReceiver(): void
    {
        $input = [
            'name' => fake()->word,
            'agents_id' => auth()->user()->getId(),
            'is_default' => true,
            'rotations_id' => 1,
            'source_name' => 'source',
            'lead_sources_id' => 0,
            'lead_types_id' => 0,
            'template' => 'template',
        ];
        $response = $this->graphQL(
            'mutation createLeadReceiver($input: LeadReceiverInput!) {
                createLeadReceiver(input: $input){
                    id
                }
            }',
            [
                'input' => $input,
            ]
        );
        $id = $response->json('data.createLeadReceiver.id');
        $input['name'] = 'new name';
        $this->graphQL(
            'mutation updateLeadReceiver($id: ID!, $input: LeadReceiverInput!) {
                updateLeadReceiver(id: $id, input: $input){
                    name,
                    agent{
                        id
                    },
                    is_default,
                    source_name,
                    template
                }
            }',
            [
                'id' => $id,
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'updateLeadReceiver' => [
                    'name' => $input['name'],
                    'agent' => [
                        'id' => $input['agents_id'],
                    ],
                    'is_default' => $input['is_default'],
                    'source_name' => $input['source_name'],
                    'template' => $input['template'],
                ],
            ],
        ]);
    }

    public function testDeleteLeadReceiver(): void
    {
        $input = [
            'name' => fake()->word,
            'agents_id' => auth()->user()->getId(),
            'is_default' => true,
            'rotations_id' => 1,
            'source_name' => 'source',
            'lead_sources_id' => 0,
            'lead_types_id' => 0,
        ];
        $response = $this->graphQL(
            'mutation createLeadReceiver($input: LeadReceiverInput!) {
                createLeadReceiver(input: $input){
                    id
                }
            }',
            [
                'input' => $input,
            ]
        );
        $id = $response->json('data.createLeadReceiver.id');
        $this->graphQL(
            'mutation deleteLeadReceiver($id: ID!) {
                deleteLeadReceiver(id: $id)
            }',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteLeadReceiver' => true,
            ],
        ]);
    }

    public function testGetLeadReceivers(): void
    {
        $input = [
            'name' => fake()->word,
            'agents_id' => auth()->user()->getId(),
            'is_default' => true,
            'rotations_id' => 1,
            'source_name' => 'source',
            'lead_sources_id' => 0,
            'lead_types_id' => 0,
        ];
        $response = $this->graphQL(
            'mutation createLeadReceiver($input: LeadReceiverInput!) {
                createLeadReceiver(input: $input){
                    id
                }
            }',
            [
                'input' => $input,
            ]
        );
        $id = $response->json('data.createLeadReceiver.id');
        $this->graphQL(
            '{
                leadReceivers{
                    data {
                        id
                    }   
                }
            }'
        )->assertJsonStructure([
            'data' => [
                'leadReceivers' => [
                    'data' => [
                        '*' => [
                            'id',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
