<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadTest extends TestCase
{
    public function testGetLeads(): void
    {
        $this->graphQL('
            query {
                leads {
                    data {
                        uuid
                        title
                    }
                }
            }')->assertOk();
    }

    public function testCreateLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
            'branch_id' => $branch->getId(),
            'title' => $title,
            'pipeline_stage_id' => 0,
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
            ],
        ];

        $this->graphQL('
        mutation($input: LeadInput!) {
            createLead(input: $input) {                
                title
            }
        }
    ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createLead' => [
                    'title' => $title,
                ],
            ],
        ]);
    }

    public function testUpdateLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
                   'branch_id' => $branch->getId(),
                   'title' => $title,
                   'pipeline_stage_id' => 0,
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
                   ],
               ];

        $response = $this->graphQL('
        mutation($input: LeadInput!) {
            createLead(input: $input) {                
                id,
                people {
                    id
                }
            }
        }
    ', [
            'input' => $input,
    ])->json();

        $leadId = $response['data']['createLead']['id'];
        $peopleId = $response['data']['createLead']['people']['id'];

        $input = [
            'branch_id' => $branch->getId(),
            'title' => $title,
            'people_id' => $peopleId,
        ];

        $this->graphQL('
        mutation($id: Int!, $input: LeadUpdateInput!) {
            updateLead(id: $id, input: $input) {
                id
                title
            }
        }
    ', [
            'id' => $leadId,
            'input' => $input,
        ])->assertJson([
            'data' => [
                'updateLead' => [
                    'id' => $leadId,
                    'title' => $title,

                ],
            ],
        ]);
    }

    public function testDeleteLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
                   'branch_id' => $branch->getId(),
                   'title' => $title,
                   'pipeline_stage_id' => 0,
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
                   ],
               ];

        $response = $this->graphQL('
        mutation($input: LeadInput!) {
            createLead(input: $input) {                
                id,
                people {
                    id
                }
            }
        }
    ', [
            'input' => $input,
    ])->json();

        $leadId = $response['data']['createLead']['id'];


        $this->graphQL('
        mutation($id: Int!) {
            deleteLead(id: $id)
        }
    ', [
            'id' => $leadId,
        ])->assertJson([
            'data' => [
                'deleteLead' => true,
            ],
        ]);
    }

    public function testRestoreLead()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        $input = [
                   'branch_id' => $branch->getId(),
                   'title' => $title,
                   'pipeline_stage_id' => 0,
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
                   ],
               ];

        $response = $this->graphQL('
        mutation($input: LeadInput!) {
            createLead(input: $input) {                
                id,
                people {
                    id
                }
             }
            }
        ', [
                'input' => $input,
        ])->json();

        $leadId = $response['data']['createLead']['id'];


        $this->graphQL('
            mutation($id: Int!) {
                deleteLead(id: $id)
            }
        ', [
                'id' => $leadId,
            ])->assertJson([
                'data' => [
                    'deleteLead' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: Int!) {
                restoreLead(id: $id)
            }
        ', [
                'id' => $leadId,
            ])->assertJson([
                'data' => [
                    'restoreLead' => true,
                ],
            ]);
    }
}
