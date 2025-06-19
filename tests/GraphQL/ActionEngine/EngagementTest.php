<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class EngagementTest extends TestCase
{
    public function testEngagementByFilter()
    {
        //todo add test
        $this->markTestIncomplete('TODO: Implement the test logic for EngagementByFilter query.');
    }
    /**
     * @todo add the action engine setup
     */
    /*     protected function createLeadAndGetResponse(array $input = [])
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

        public function testStartLeadEngagement()
        {
            // Create a lead first
            $lead = $this->createLeadAndGetResponse();
            $leadId = $lead['data']['createLead']['id'];
            $peopleId = $lead['data']['createLead']['people']['id'];

            // Generate test data
            $requestId = fake()->uuid();

            $response = $this->graphQL('
                mutation StartLeadEngagement($input: CreateEngagementInput!) {
                    startLeadEngagement(input: $input) {
                        id
                        uuid
                        entity_uuid
                        slug
                        message {
                            message
                        }
                    }
                }
            ', [
                'input' => [
                    'lead_id' => $leadId,
                    'people_id' => $peopleId,
                    'request_id' => $requestId,
                    'source' => 'sandra-ai',
                    'status' => 'sent',
                    'action' => 'credit-app',
                    'data' => [],
                ],
            ]);

            $response->assertOk();

            $responseData = $response->json();

            //print_R($responseData); die();

            // Assert the response structure and required fields
            $this->assertArrayHasKey('data', $responseData);
            $this->assertArrayHasKey('startLeadEngagement', $responseData['data']);

            $engagement = $responseData['data']['startLeadEngagement'];

            // Assert required fields are present
            $this->assertArrayHasKey('id', $engagement);
            $this->assertArrayHasKey('uuid', $engagement);
            $this->assertArrayHasKey('entity_uuid', $engagement);
            $this->assertArrayHasKey('slug', $engagement);
            $this->assertArrayHasKey('message', $engagement);

            // Assert values are not null/empty
            $this->assertNotNull($engagement['id']);
            $this->assertNotNull($engagement['uuid']);
            $this->assertNotNull($engagement['entity_uuid']);
            $this->assertNotNull($engagement['slug']);

            // If message exists, check its structure
            if ($engagement['message']) {
                $this->assertArrayHasKey('message', $engagement['message']);
            }
        } */
}
