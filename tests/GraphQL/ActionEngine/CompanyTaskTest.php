<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class CompanyTaskTest extends TestCase
{
    protected function createLeadAndGetResponse(array $input = [])
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        if (empty($input)) {
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
                    'address' => [
                        [
                            'address' => fake()->address(),
                            'city' => fake()->city(),
                            'state' => fake()->state(),
                            'country' => fake()->country(),
                            'zip' => fake()->postcode(),
                        ],
                    ],
                ],
                'custom_fields' => [],
                'files' => [
                    [
                        'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                        'name' => 'dummy.pdf',
                    ],
                ],
            ];
        }

        return $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {                
                    id
                    uuid
                    people {
                        id
                    },
                    systemModule{
                        id
                    }
                }
            }
        ', [
            'input' => $input,
        ])->json();
    }

    public function testGetLeadTaskEngagement()
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];

        $this->graphQL('
        query leadTasks($lead_id: ID!) {
            leadTaskItems(lead_id: $lead_id) {
                data {
                    name
                    company_action {
                        name
                        action {
                            slug
                        }
                    }
                    status
                    config
                    engagement_start {
                        uuid
                        message {
                            message
                        }
                        lead {
                            title
                        }
                        slug
                        entity_uuid
                    } engagement_end {
                        uuid
                    }
                }
            }
        }
    ', [
        'lead_id' => $leadId, // Passing the lead ID to the GraphQL query
    ])->assertOk();
    }

    public function testSubscribeToLeadTask()
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];

        $this->graphQL('
        subscription leadTaskItemUpdated($lead_id: ID!) {
            leadTaskItemUpdated(lead_id: $lead_id) {
                id
                status
            }
        }

    ', [
        'lead_id' => $leadId, // Passing the lead ID to the GraphQL query
    ])->assertOk();
    }
}
