<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class EngagementUpdateTest extends TestCase
{
    protected function createEngagement(): array
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];
        $peopleId = $lead['data']['createLead']['people']['id'];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new SyncEmailTemplateAction($app, $user)->execute();

        $actions = [[
            'id' => 7,
            'name' => 'credit-app',
            'description' => 'Credit App',
            'title' => 'Credit App',
            'enable' => true,
            'icon' => '',
            'reasonEn' => 'apply for financing',
            'reasonEs' => 'apply for financing',
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        $response = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) {
                    id
                    uuid
                    slug
                    stage {
                        id
                        name
                    }
                    message {
                        id
                        message
                    }
                }
            }
        ', [
            'input' => [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => fake()->uuid(),
                'source' => 'kanvas-ai',
                'status' => 'sent',
                'action' => 'credit-app',
                'data' => [],
            ],
        ]);

        $json = $response->json();
        if (! empty($json['errors'])) {
            $this->fail('GraphQL errors in createEngagement: ' . json_encode($json['errors']));
        }

        $response->assertSuccessful();

        return $response->json('data.startLeadEngagement');
    }

    protected function createLeadAndGetResponse(array $input = [])
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        if (empty($input)) {
            $input = [
                'branch_id' => $branch->getId(),
                'title' => fake()->title(),
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
                'files' => [],
            ];
        }

        return $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    id
                    uuid
                    people {
                        id
                    }
                }
            }
        ', ['input' => $input])->json();
    }

    public function testUpdateEngagementStatus(): void
    {
        $engagement = $this->createEngagement();

        $response = $this->graphQL('
            mutation($uuid: ID!, $input: UpdateEngagementInput!) {
                updateEngagement(uuid: $uuid, input: $input) {
                    id
                    uuid
                    stage {
                        id
                        name
                    }
                }
            }
        ', [
            'uuid' => $engagement['uuid'],
            'input' => [
                'status' => 'opened',
                'data' => ['updated' => true],
            ],
        ]);

        $json = $response->json();
        if (! empty($json['errors'])) {
            $this->fail('GraphQL errors: ' . json_encode($json['errors']));
        }

        $response->assertSuccessful();
        $updated = $response->json('data.updateEngagement');
        $this->assertEquals($engagement['id'], $updated['id']);
        $this->assertNotEquals($engagement['stage']['id'], $updated['stage']['id']);
    }

    public function testUpdateEngagementMessageData(): void
    {
        $engagement = $this->createEngagement();

        $response = $this->graphQL('
            mutation($uuid: ID!, $input: UpdateEngagementInput!) {
                updateEngagement(uuid: $uuid, input: $input) {
                    id
                    uuid
                    message {
                        message
                    }
                }
            }
        ', [
            'uuid' => $engagement['uuid'],
            'input' => [
                'data' => ['custom_key' => 'custom_value'],
                'description' => 'Updated engagement description',
                'via' => 'sms',
            ],
        ]);

        $json = $response->json();
        if (! empty($json['errors'])) {
            $this->fail('GraphQL errors: ' . json_encode($json['errors']));
        }

        $response->assertSuccessful();
        $updated = $response->json('data.updateEngagement');
        $this->assertEquals($engagement['id'], $updated['id']);

        $messageData = $updated['message']['message'];
        $this->assertEquals('Updated engagement description', $messageData['description']);
        $this->assertEquals('sms', $messageData['via']);
        $this->assertEquals('custom_value', $messageData['data']['custom_key']);
    }

    public function testUpdateEngagementAll(): void
    {
        $engagement = $this->createEngagement();

        $response = $this->graphQL('
            mutation($uuid: ID!, $input: UpdateEngagementInput!) {
                updateEngagement(uuid: $uuid, input: $input) {
                    id
                    uuid
                    stage {
                        name
                    }
                    message {
                        message
                    }
                }
            }
        ', [
            'uuid' => $engagement['uuid'],
            'input' => [
                'status' => 'submitted',
                'data' => ['result' => 'approved'],
                'description' => 'Final submission',
                'via' => 'email',
            ],
        ]);

        $json = $response->json();
        if (! empty($json['errors'])) {
            $this->fail('GraphQL errors: ' . json_encode($json['errors']));
        }

        $response->assertSuccessful();
        $updated = $response->json('data.updateEngagement');
        $this->assertEquals($engagement['id'], $updated['id']);

        $messageData = $updated['message']['message'];
        $this->assertEquals('Final submission', $messageData['description']);
        $this->assertEquals('email', $messageData['via']);
        $this->assertEquals('approved', $messageData['data']['result']);
    }

    public function testUpdateEngagementNotFound(): void
    {
        $response = $this->graphQL('
            mutation($uuid: ID!, $input: UpdateEngagementInput!) {
                updateEngagement(uuid: $uuid, input: $input) {
                    id
                }
            }
        ', [
            'uuid' => fake()->uuid(),
            'input' => [
                'status' => 'opened',
                'data' => [],
            ],
        ]);

        $this->assertNotEmpty($response->json('errors'));
    }
}
