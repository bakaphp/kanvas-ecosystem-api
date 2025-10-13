<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    public function testEngagementByFilter()
    {
        //todo add test
        $this->markTestIncomplete('TODO: Implement the test logic for EngagementByFilter query.');
    }

    protected function createLeadAndGetResponse(array $input = [])
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();
        $app = app(Apps::class);
        $syncEmailTemplate = new SyncEmailTemplateAction($app, $user);
        $syncEmailTemplate->execute();

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
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $syncEmailTemplate = new SyncEmailTemplateAction($app, $user);
        $syncEmailTemplate->execute();

        $actions = [[
                'id' => 7,
                'name' => 'credit-app',
                'description' => 'Credit App',
                'title' => 'Credit App',
                'enable' => true,
                'icon' => '',
                'reasonEn' => 'apply for financing',
                'reasonEs' => 'apply for financing',
                'form_fields' => '{"personal":{"type":"object","required":1},"housing":{"type":"object","required":1},"financial":{"type":"object","required":1}}',
                'form_config' => '{"require_credit-app_signature":true}',
            ]];

        new Setup($app, $user, $company, $actions)->run();

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
                'source' => 'kanvas-ai',
                'status' => 'sent',
                'action' => 'credit-app',
                'data' => [],
            ],
        ]);

        $response->assertOk();

        $responseData = $response->json();

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
    }

    public function testContinueLeadEngagement()
    {
        // Create a lead first
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];
        $peopleId = $lead['data']['createLead']['people']['id'];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $actions = [[
                'id' => 7,
                'name' => 'credit-app',
                'description' => 'Credit App',
                'title' => 'Credit App',
                'enable' => true,
                'icon' => '',
                'reasonEn' => 'apply for financing',
                'reasonEs' => 'apply for financing',
                'form_fields' => '{"personal":{"type":"object","required":1},"housing":{"type":"object","required":1},"financial":{"type":"object","required":1}}',
                'form_config' => '{"require_credit-app_signature":true}',
            ]];

        new Setup($app, $user, $company, $actions)->run();

        // Generate test data
        $requestId = fake()->uuid();

        // First, start the lead engagement
        $startResponse = $this->graphQL('
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
                'source' => 'kanvas-ai',
                'status' => 'sent',
                'action' => 'credit-app',
                'data' => [],
            ],
        ]);

        $startResponse->assertOk();

        // Now continue the lead engagement
        $continueResponse = $this->graphQL('
                mutation ContinueLeadEngagement($input: CreateEngagementInput!) {
                    continueLeadEngagement(input: $input) {
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
                'source' => 'api',
                'status' => 'opened',
                'action' => 'credit-app',
                'data' => [],
            ],
        ]);

        $continueResponse->assertOk();

        $responseData = $continueResponse->json();
        // Assert the response structure and required fields
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('continueLeadEngagement', $responseData['data']);

        $engagement = $responseData['data']['continueLeadEngagement'];

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
    }

    public function testListEngagements()
    {
        // Create a lead first
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];
        $peopleId = $lead['data']['createLead']['people']['id'];
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $actions = [[
            'id' => 7,
            'name' => 'credit-app',
            'description' => 'Credit App',
            'title' => 'Credit App',
            'enable' => true,
            'icon' => '',
            'reasonEn' => 'apply for financing',
            'reasonEs' => 'apply for financing',
            'form_fields' => '{"personal":{"type":"object","required":1},"housing":{"type":"object","required":1},"financial":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        // Create multiple engagements
        $requestId1 = fake()->uuid();
        $requestId2 = fake()->uuid();

        // Create first engagement
        $this->graphQL('
        mutation StartLeadEngagement($input: CreateEngagementInput!) {
            startLeadEngagement(input: $input) {
                id
                uuid
            }
        }
    ', [
            'input' => [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => $requestId1,
                'source' => 'kanvas-ai',
                'status' => 'sent',
                'action' => 'credit-app',
                'data' => [],
            ],
        ])->assertOk();

        // Create second engagement
        $this->graphQL('
        mutation StartLeadEngagement($input: CreateEngagementInput!) {
            startLeadEngagement(input: $input) {
                id
                uuid
            }
        }
    ', [
            'input' => [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => $requestId2,
                'source' => 'api',
                'status' => 'opened',
                'action' => 'credit-app',
                'data' => [],
            ],
        ])->assertOk();

        // Query engagements list
        $response = $this->graphQL('
        query GetEngagements($first: Int!) {
            engagements(first: $first, orderBy: [{ column: CREATED_AT, order: DESC }]) {
                paginatorInfo {
                    count
                    currentPage
                    total
                }
                data {
                    id
                    uuid
                    entity_uuid
                    slug
                    user {
                        id
                        firstname
                    }
                    company_action {
                        id
                        name
                    }
                    message {
                        id
                        message
                    }
                    lead {
                        id
                        title
                    }
                    people {
                        id
                        firstname
                    }
                }
            }
        }
    ', [
            'first' => 10,
        ]);

        $response->assertOk();

        $responseData = $response->json();

        // Assert the response structure
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('engagements', $responseData['data']);
        $this->assertArrayHasKey('paginatorInfo', $responseData['data']['engagements']);
        $this->assertArrayHasKey('data', $responseData['data']['engagements']);

        // Assert pagination info
        $paginatorInfo = $responseData['data']['engagements']['paginatorInfo'];
        $this->assertArrayHasKey('count', $paginatorInfo);
        $this->assertArrayHasKey('currentPage', $paginatorInfo);
        $this->assertArrayHasKey('total', $paginatorInfo);
        $this->assertGreaterThanOrEqual(2, $paginatorInfo['count']);

        // Assert engagement data
        $engagements = $responseData['data']['engagements']['data'];
        $this->assertNotEmpty($engagements);
        $this->assertGreaterThanOrEqual(2, count($engagements));

        // Assert structure of first engagement
        $firstEngagement = $engagements[0];
        $this->assertArrayHasKey('id', $firstEngagement);
        $this->assertArrayHasKey('uuid', $firstEngagement);
        $this->assertArrayHasKey('entity_uuid', $firstEngagement);
        $this->assertArrayHasKey('slug', $firstEngagement);
        $this->assertArrayHasKey('user', $firstEngagement);
        $this->assertArrayHasKey('company_action', $firstEngagement);
        $this->assertArrayHasKey('message', $firstEngagement);
        $this->assertArrayHasKey('lead', $firstEngagement);
        $this->assertArrayHasKey('people', $firstEngagement);

        // Assert values are not null
        $this->assertNotNull($firstEngagement['id']);
        $this->assertNotNull($firstEngagement['uuid']);
        $this->assertNotNull($firstEngagement['entity_uuid']);
        $this->assertNotNull($firstEngagement['slug']);
    }
}
