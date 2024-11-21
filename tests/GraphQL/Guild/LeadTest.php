<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
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
                    status {
                        id
                        name
                    }
                }
            }
        ', [
            'input' => $input,
        ])->json();
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'organization' => [
                'name' => fake()->company(),
                'address' => fake()->address(),
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);

        $leadId = $response['data']['createLead']['id'];
        $peopleId = $response['data']['createLead']['people']['id'];

        $input = [
            'branch_id' => $branch->getId(),
            'title' => $title,
            'people_id' => $peopleId,
            'custom_fields' => [],
            'files' => [],
        ];

        $this->graphQL('
        mutation($id: ID!, $input: LeadUpdateInput!) {
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);

        $leadId = $response['data']['createLead']['id'];

        $this->graphQL('
        mutation($id: ID!) {
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);

        $leadId = $response['data']['createLead']['id'];

        $this->graphQL('
            mutation($id: ID!) {
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
            mutation($id: ID!) {
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

    public function testDashboard()
    {
        $this->graphQL('
        {
            leadsDashboard(first: 1, 
                where: {
                    column: USERS_ID, operator: EQ, value: 1186
                    } 
            ) {
                data {
                    total_active_leads
                    total_closed_leads
                    total_agents
                }
                
            }
        }')->assertSuccessful()
            ->assertSee('total_active_leads')
            ->assertSee('total_closed_leads')
            ->assertSee('total_agents');
    }

    public function testFollowLead()
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);

        $leadUuid = $response['data']['createLead']['uuid'];

        $this->graphQL('
        mutation($input: FollowInput!) {
            followLead(input: $input)
            }
        ', [
        'input' => [
            'entity_id' => $leadUuid,
            'user_id' => $user->getId(),
        ],
        ])->assertJson([
            'data' => [
                'followLead' => true,
            ],
        ]);
    }

    public function testUnFollowLead()
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);

        $leadUuid = $response['data']['createLead']['uuid'];

        $this->graphQL('
        mutation($input: FollowInput!) {
            followLead(input: $input)
            }
        ', [
        'input' => [
            'entity_id' => $leadUuid,
            'user_id' => $user->getId(),
        ],
        ])->assertJson([
            'data' => [
                'followLead' => true,
            ],
        ]);

        $this->graphQL('
        mutation($input: FollowInput!) {
            unFollowLead(input: $input)
            }
        ', [
        'input' => [
            'entity_id' => $leadUuid,
            'user_id' => $user->getId(),
        ],
        ])->assertJson([
            'data' => [
                'unFollowLead' => true,
            ],
        ]);
    }

    public function testChannelMessage()
    {
        $lead = $this->createLeadAndGetResponse();
        $channel = $this->graphQL('
            query socialChannels($where: QuerySocialChannelsWhereWhereConditions) {
                socialChannels(where: $where) {
                    data {
                        id
                        uuid
                        slug
                    }
                }
            }
        ', ['where' => ['column' => 'SLUG', 'operator' => 'EQ', 'value' => $lead['data']['createLead']['uuid']]]);
        $channel->assertJson([
            'data' => [
                'socialChannels' => [
                    'data' => [
                        [
                            'slug' => $lead['data']['createLead']['uuid'],
                        ],
                    ],
                ],
            ],
        ]);
        $channel = $channel->json()['data']['socialChannels']['data'][0];
        $messageType = MessageType::factory()->create();
        $messageInput = [
            'message' => json_encode($lead['data']['createLead']),
            'message_verb' => $messageType->verb,
            'system_modules_id' => $lead['data']['createLead']['systemModule']['id'],
            'entity_id' => $lead['data']['createLead']['id'],
            'distribution' => [
                'distributionType' => 'Channels',
                'channels' => [
                    $channel['id'],
                ],
                'followers' => [],
            ],
        ];

        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        message
                    }
                }
            ',
            [
                'input' => $messageInput,
            ]
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => json_encode($lead['data']['createLead']),
                ],
            ],
        ]);

        $message = $this->graphQL(
            '
            query($channel_uuid: String!) {
                channelMessages(
                    channel_uuid: $channel_uuid
                ) {
                    data {
                        message
                    }
                }
            }
        ',
            [
            'channel_uuid' => $channel['uuid'],
        ]
        );
        $message->assertJsonStructure([
        'data' => [
            'channelMessages' => [
                'data' => [
                    '*' => [
                        'message',
                    ],
                ],
            ],
        ],
        ]);
    }

    public function testLeadSubscription()
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = $lead['data']['createLead']['id'];

        $this->graphQL('
        subscription leadUpdate($lead_id: ID!) {
            leadUpdate(id: $lead_id) {
                id
                title
            }
        }

    ', [
        'lead_id' => $leadId, // Passing the lead ID to the GraphQL query
    ])->assertOk();
    }

    public function testCreationOfDuplicateLeads()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $currentCompany = $user->getCurrentCompany();
        $currentCompany->set(FlagEnum::COMPANY_CANT_HAVE_MULTIPLE_OPEN_LEADS->value, 1);
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
                'address' => [
                    [
                        'address' => fake()->address(),
                        'city' => fake()->city(),
                        'state' => fake()->state(),
                        'country' => fake()->country(),
                        'zip' => fake()->postcode(),
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [
                [
                    'name' => 'test',
                    'data' => 'test',
                ],
            ],
            'files' => [
                [
                    'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    'name' => 'dummy.pdf',
                ],
            ],
        ];

        $response = $this->createLeadAndGetResponse($input);
        $response = $this->createLeadAndGetResponse($input);

        $this->assertTrue($response['data']['createLead']['status']['name'] === 'Duplicate');
    }
}
