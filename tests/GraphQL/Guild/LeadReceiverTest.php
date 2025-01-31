<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class LeadReceiverTest extends TestCase
{
    public function testCreateLeadReceiver(): void
    {
        $input = [
            'name' => fake()->word,
            'agents_id' => auth()->user()->getId(),
            'is_default' => true,
            'rotations_id' => 1,
            'source_name' => 'source',
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
