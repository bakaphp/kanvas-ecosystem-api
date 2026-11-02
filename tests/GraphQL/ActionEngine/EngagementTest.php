<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Illuminate\Testing\TestResponse;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Actions\CreateAppKeyAction;
use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\DataTransferObject\AppKeyInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;
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

    public function testCreateEngagementWithParent(): void
    {
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
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        // Create parent engagement
        $parentResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) {
                    id
                    uuid
                    is_complete
                    completion_progress {
                        completed
                        total
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
        ])->assertSuccessful();

        $parentId = $parentResponse->json('data.startLeadEngagement.id');
        $this->assertNotNull($parentId);
        $this->assertFalse($parentResponse->json('data.startLeadEngagement.is_complete'));
        $this->assertEquals(0, $parentResponse->json('data.startLeadEngagement.completion_progress.total'));

        // Create child engagement linked to parent
        $childResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) {
                    id
                    uuid
                    parent {
                        id
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
                'parent_id' => $parentId,
            ],
        ])->assertSuccessful();

        $childId = $childResponse->json('data.startLeadEngagement.id');
        $this->assertNotNull($childId);
        $this->assertEquals($parentId, $childResponse->json('data.startLeadEngagement.parent.id'));
    }

    public function testContinueLeadEngagementLinksTheParentMessage(): void
    {
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
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        $parentResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) {
                    id
                    message {
                        id
                        parent_id
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
        ])->assertSuccessful();

        $parentId = $parentResponse->json('data.startLeadEngagement.id');
        $parentMessageId = $parentResponse->json('data.startLeadEngagement.message.id');

        $this->assertNotNull($parentMessageId);
        $this->assertNull($parentResponse->json('data.startLeadEngagement.message.parent_id'));

        $childResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                continueLeadEngagement(input: $input) {
                    id
                    parent {
                        id
                    }
                    message {
                        id
                        parent_id
                    }
                }
            }
        ', [
            'input' => [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => fake()->uuid(),
                'source' => 'api',
                'status' => 'opened',
                'action' => 'credit-app',
                'data' => [],
                'parent_id' => $parentId,
            ],
        ])->assertSuccessful();

        $childMessageId = $childResponse->json('data.continueLeadEngagement.message.id');

        $this->assertEquals($parentId, $childResponse->json('data.continueLeadEngagement.parent.id'));
        $this->assertNotEquals($parentMessageId, $childMessageId);
        $this->assertEquals(
            $parentMessageId,
            $childResponse->json('data.continueLeadEngagement.message.parent_id')
        );

        $orphanResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                continueLeadEngagement(input: $input) {
                    id
                    message {
                        id
                        parent_id
                    }
                }
            }
        ', [
            'input' => [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => fake()->uuid(),
                'source' => 'api',
                'status' => 'opened',
                'action' => 'credit-app',
                'data' => [],
            ],
        ])->assertSuccessful();

        $this->assertNotNull($orphanResponse->json('data.continueLeadEngagement.message.id'));
        $this->assertNull($orphanResponse->json('data.continueLeadEngagement.message.parent_id'));
    }

    public function testEngagementChildrenAndCompletionProgress(): void
    {
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
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        // Create parent engagement
        $parentResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) { id }
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
        ])->assertSuccessful();

        $parentId = $parentResponse->json('data.startLeadEngagement.id');

        // Create two child engagements
        $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) { id }
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
                'parent_id' => $parentId,
            ],
        ])->assertSuccessful();

        $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) { id }
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
                'parent_id' => $parentId,
            ],
        ])->assertSuccessful();

        // Query parent with children, stage, and completion progress
        $response = $this->graphQL('
            query($where: QueryEngagementsWhereWhereConditions) {
                engagements(first: 1, where: $where) {
                    data {
                        id
                        children {
                            id
                            stage {
                                id
                                name
                            }
                        }
                        is_complete
                        completion_progress {
                            completed
                            total
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $parentId,
            ],
        ])->assertSuccessful();

        $parent = $response->json('data.engagements.data.0');
        $this->assertNotNull($parent);
        $this->assertCount(2, $parent['children']);
        $this->assertFalse($parent['is_complete']);
        $this->assertEquals(2, $parent['completion_progress']['total']);
        $this->assertEquals(0, $parent['completion_progress']['completed']);

        // Verify each child has stage info
        foreach ($parent['children'] as $child) {
            $this->assertArrayHasKey('stage', $child);
        }
    }

    public function testEngagementStageHistory(): void
    {
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
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ]];

        new Setup($app, $user, $company, $actions)->run();

        // Same request_id for the entire journey
        $requestId = fake()->uuid();

        // Step 1: Send the engagement
        $sentResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) {
                    id
                    entity_uuid
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
        ])->assertSuccessful();

        $sentId = $sentResponse->json('data.startLeadEngagement.id');
        $this->assertEquals($requestId, $sentResponse->json('data.startLeadEngagement.entity_uuid'));

        // Step 2: Customer opens it (same request_id)
        $openedResponse = $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                continueLeadEngagement(input: $input) {
                    id
                    entity_uuid
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
        ])->assertSuccessful();

        $openedId = $openedResponse->json('data.continueLeadEngagement.id');
        $this->assertEquals($requestId, $openedResponse->json('data.continueLeadEngagement.entity_uuid'));
        $this->assertNotEquals($sentId, $openedId);

        // Query the sent engagement and check stage_history
        $response = $this->graphQL('
            query($where: QueryEngagementsWhereWhereConditions) {
                engagements(first: 1, where: $where) {
                    data {
                        id
                        entity_uuid
                        stage_history {
                            id
                            entity_uuid
                            stage {
                                id
                                name
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $sentId,
            ],
        ])->assertSuccessful();

        $engagement = $response->json('data.engagements.data.0');
        $this->assertNotNull($engagement);
        $this->assertEquals($requestId, $engagement['entity_uuid']);

        // stage_history should contain both the sent and opened records
        $this->assertCount(2, $engagement['stage_history']);

        // All records share the same entity_uuid
        foreach ($engagement['stage_history'] as $history) {
            $this->assertEquals($requestId, $history['entity_uuid']);
            $this->assertArrayHasKey('stage', $history);
        }
    }

    public function testContinueOnAppKeyOnlyBorrowsTheParentEngagementUser(): void
    {
        $app = app(Apps::class);
        $rep = auth()->user();
        $company = $rep->getCurrentCompany();
        [$leadId, $peopleId] = $this->createLeadWithCreditApp($rep);

        $parentId = $this->startEngagementAsRep($leadId, $peopleId, fake()->uuid());
        $keyOwner = $this->registerHumanInCompany($company, $app);
        $appKey = $this->createAppKeyFor($keyOwner, $app);

        $response = $this->continueEngagement(
            [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => fake()->uuid(),
                'source' => 'api',
                'status' => 'submitted',
                'action' => 'credit-app',
                'data' => [],
                'parent_id' => $parentId,
            ],
            [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $appKey]
        );

        $engagementId = $response->json('data.continueLeadEngagement.id');

        $this->assertEquals($rep->getId(), $response->json('data.continueLeadEngagement.user.id'));
        $this->assertNotEquals($keyOwner->getId(), $response->json('data.continueLeadEngagement.user.id'));

        $engagement = Engagement::getById($engagementId, $app);
        $this->assertEquals($rep->getId(), $engagement->users_id);
        $this->assertEquals($rep->getId(), $engagement->message->users_id);
    }

    public function testContinueOnAppKeyOnlyFallsBackToTheRequestIdChain(): void
    {
        $app = app(Apps::class);
        $rep = auth()->user();
        $company = $rep->getCurrentCompany();
        [$leadId, $peopleId] = $this->createLeadWithCreditApp($rep);

        $requestId = fake()->uuid();
        $this->startEngagementAsRep($leadId, $peopleId, $requestId);
        $keyOwner = $this->registerHumanInCompany($company, $app);
        $appKey = $this->createAppKeyFor($keyOwner, $app);

        $response = $this->continueEngagement(
            [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => $requestId,
                'source' => 'api',
                'status' => 'opened',
                'action' => 'credit-app',
                'data' => [],
            ],
            [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $appKey]
        );

        $this->assertEquals($rep->getId(), $response->json('data.continueLeadEngagement.user.id'));
    }

    public function testContinueWithBearerKeepsTheLoggedInUserEvenWithAnAppKey(): void
    {
        $app = app(Apps::class);
        $rep = auth()->user();
        $company = $rep->getCurrentCompany();
        [$leadId, $peopleId] = $this->createLeadWithCreditApp($rep);

        $parentId = $this->startEngagementAsRep($leadId, $peopleId, fake()->uuid());
        $keyOwner = $this->registerHumanInCompany($company, $app);
        $appKey = $this->createAppKeyFor($keyOwner, $app);

        $password = fake()->password(12);
        $manager = $this->registerHumanInCompany($company, $app, $password);
        $token = $this->graphQL('
            mutation($data: LoginInput!) {
                login(data: $data) { token }
            }
        ', [
            'data' => [
                'email' => $manager->email,
                'password' => $password,
            ],
        ])->assertSuccessful()->json('data.login.token');

        // The guard is built once at boot with that request; Laravel's own guards get the per-request
        // instance through this rebinding, our custom one is registered without it.
        $guard = auth()->guard('api');
        $guard->forgetUser();
        app()->refresh('request', $guard, 'setRequest');

        $response = $this->continueEngagement(
            [
                'lead_id' => $leadId,
                'people_id' => $peopleId,
                'request_id' => fake()->uuid(),
                'source' => 'api',
                'status' => 'submitted',
                'action' => 'credit-app',
                'data' => [],
                'parent_id' => $parentId,
            ],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $appKey,
                'Authorization' => 'Bearer ' . $token,
            ]
        );

        $this->assertEquals($manager->getId(), $response->json('data.continueLeadEngagement.user.id'));
    }

    /**
     * @return array{0: int|string, 1: int|string}
     */
    private function createLeadWithCreditApp(Users $rep): array
    {
        $lead = $this->createLeadAndGetResponse();

        new Setup(
            app(Apps::class),
            $rep,
            $rep->getCurrentCompany(),
            [[
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
            ]]
        )->run();

        return [$lead['data']['createLead']['id'], $lead['data']['createLead']['people']['id']];
    }

    private function startEngagementAsRep(int|string $leadId, int|string $peopleId, string $requestId): int|string
    {
        return $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                startLeadEngagement(input: $input) { id }
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
        ])->assertSuccessful()->json('data.startLeadEngagement.id');
    }

    private function continueEngagement(array $input, array $headers): TestResponse
    {
        return $this->graphQL('
            mutation($input: CreateEngagementInput!) {
                continueLeadEngagement(input: $input) {
                    id
                    user { id }
                }
            }
        ', ['input' => $input], [], $headers)->assertSuccessful();
    }

    /**
     * apps_keys is keyed on (apps_id, users_id), so each key needs its own owner; and the owner's
     * current company must be the lead's company, because the key-only path resolves the tenant from
     * the key's user.
     */
    private function registerHumanInCompany(Companies $company, Apps $app, ?string $password = null): Users
    {
        $human = new RegisterUsersAction(RegisterInput::from([
            'email' => fake()->unique()->safeEmail(),
            'password' => $password ?? fake()->password(12),
            'firstname' => fake()->firstName(),
            'lastname' => fake()->lastName(),
        ]))->execute();

        $branch = $company->branch()->first();
        $company->associateUser($human, StateEnums::YES->getValue(), $branch);
        $company->associateUserApp($human, $app, StateEnums::YES->getValue());
        $human->default_company = $company->getId();
        $human->default_company_branch = (int) $branch->getId();
        $human->saveOrFail();
        $human->set(Companies::cacheKey(), $company->getId());

        return $human;
    }

    private function createAppKeyFor(Users $owner, Apps $app): string
    {
        return new CreateAppKeyAction(
            new AppKeyInput('engagement-continue-' . fake()->unique()->uuid(), $app, $owner),
            createUserInApp: false
        )->execute()->client_secret_id;
    }
}
