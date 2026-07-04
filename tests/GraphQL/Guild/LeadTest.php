<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
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

    /**
     * A lead's address arrives with `state_id` as a string (the shape the FE sends) and no country.
     * `Address::fromArray` must coerce the id, derive `countries_id` from the state, and resolve the
     * `city_id` scoped to that state — the resolved values must land on the persisted address row.
     */
    public function testCreateLeadResolvesAddressStateCityAndCountry(): void
    {
        // Self-seed the exact country/state/city the resolver will look up. Relying on the
        // seeded location dataset is fragile — CI seeds only a subset and may have no state
        // with both a country and cities.
        $country = Countries::create([
            'name' => fake()->unique()->country(),
            'code' => strtolower(fake()->unique()->lexify('??')),
            'flag' => '',
        ]);

        $state = States::create([
            'countries_id' => $country->id,
            'name' => fake()->unique()->state(),
            'code' => strtoupper(fake()->unique()->lexify('??')),
        ]);

        $city = Cities::create([
            'countries_id' => $country->id,
            'states_id' => $state->id,
            'name' => fake()->unique()->city(),
        ]);

        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->name();

        $input = [
            'branch_id' => $branch->getId(),
            'title' => $title,
            'pipeline_stage_id' => 0,
            'people' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
                'contacts' => [
                    [
                        'value' => fake()->unique()->email(),
                        'contacts_types_id' => 1,
                        'weight' => 0,
                    ],
                ],
                'address' => [
                    [
                        'address' => '44210 31ST ST WEST',
                        'city' => $city->name,
                        'state_id' => (string) $state->id,
                        'zip' => '935360000',
                    ],
                ],
                'custom_fields' => [],
            ],
            'custom_fields' => [],
        ];

        $response = $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    people { id }
                }
            }
        ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createLead' => [
                    'people' => [],
                ],
            ],
        ])->json();

        $peopleId = (int) $response['data']['createLead']['people']['id'];
        $people = People::getByIdFromCompanyApp($peopleId, $branch->company, app(Apps::class));
        $address = $people->address()->firstOrFail();

        $this->assertSame('44210 31ST ST WEST', $address->address);
        $this->assertSame($state->id, (int) $address->state_id);
        $this->assertSame((int) $state->countries_id, (int) $address->countries_id);
        $this->assertSame($city->id, (int) $address->city_id);
        $this->assertSame('935360000', $address->zip);
    }

    public function testCreateLeadWithoutPeopleContacts(): void
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
                'custom_fields' => [],
            ],
            'custom_fields' => [],
        ];

        $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {
                    id
                    title
                    people {
                        firstname
                        lastname
                    }
                }
            }
        ', [
            'input' => $input,
        ])->assertJsonPath('data.createLead.title', $title)
            ->assertJsonPath('data.createLead.people.firstname', $input['people']['firstname'])
            ->assertJsonPath('data.createLead.people.lastname', $input['people']['lastname']);
    }

    public function testWonLead()
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

        $response = $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {                
                    id
                }
            }
        ', [
            'input' => $input,
        ]);
        $leadId = $response->json('data.createLead.id');
        $this->graphQL('
            mutation($id: ID!) {
                leadWonOrLost(id: $id, status: Won) {
                    id
                    title
                    status {
                        name
                    }
                }
            }', [
            'id' => $leadId,
        ])->assertJson([
            'data' => [
                'leadWonOrLost' => [
                    'id' => $leadId,
                    'title' => $title,
                    'status' => [
                        'name' => 'Won',
                    ],
                ],
            ],
        ]);
    }

    public function testLostLead()
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

        $response = $this->graphQL('
            mutation($input: LeadInput!) {
                createLead(input: $input) {                
                    id
                }
            }
        ', [
            'input' => $input,
        ]);
        $leadId = $response->json('data.createLead.id');
        $this->graphQL('
            mutation($id: ID!) {
                leadWonOrLost(id: $id, status: Lost, reason_lost: "Not answer") {
                    id
                    title
                    reason_lost
                    status {
                        name
                    }
                }
            }', [
            'id' => $leadId,
        ])->assertJson([
            'data' => [
                'leadWonOrLost' => [
                    'id' => $leadId,
                    'title' => $title,
                    'reason_lost' => 'Not answer',
                    'status' => [
                        'name' => 'Lost',
                    ],
                ],
            ],
        ]);
    }

    public function testCreateLeadWithDirectFileUpload(): void
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $title = fake()->title();

        // Prepare the operations part of the multipart request
        $operations = [
            'query' => /** @lang GraphQL */ '
            mutation($input: LeadInput!) {
                createLead(input: $input) {                
                    title
                    files {
                        data{
                        uuid
                        name
                        url
                        }
                    }
                }
            }
        ',
            'variables' => [
                'input' => [
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
                        [
                            'url' => 'none',
                            'name' => 'dummy2.pdf',
                            'file' => null, // This will be mapped to the actual file
                        ],
                    ],
                ],
            ],
        ];

        // Define the map for the file in the multipart request
        $map = [
            '0' => ['variables.input.files.1.file'],
        ];

        // Create the file for the multipart request
        $file = [
            '0' => UploadedFile::fake()->create('avatar.jpg'),
        ];

        // Send the multipart GraphQL request
        $this->multipartGraphQL($operations, $map, $file)
            ->assertJson([
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

    public function testUpdateLeadWithoutTitle()
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $input = [
            'branch_id' => $branch->getId(),
            'title' => 'Original Title ' . fake()->word(),
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

        $response = $this->createLeadAndGetResponse($input);
        $leadId = $response['data']['createLead']['id'];
        $peopleId = $response['data']['createLead']['people']['id'];

        $updateInput = [
            'branch_id' => $branch->getId(),
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
            'input' => $updateInput,
        ])->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateLead' => [
                    'id' => $leadId,
                    'title' => $input['title'],
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
                    'message' => $lead['data']['createLead'],
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

    public function testAddMessageToLeadChannel(): void
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = (int) $lead['data']['createLead']['id'];
        $message = fake()->sentence();

        $response = $this->graphQL('
            mutation($input: LeadMessageInput!) {
                addMessageToLeadChannel(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'lead_id' => $leadId,
                'message' => $message,
            ],
        ])->assertJson([
            'data' => [
                'addMessageToLeadChannel' => [
                    'message' => $message,
                ],
            ],
        ])->json();

        $messageId = (int) $response['data']['addMessageToLeadChannel']['id'];
        $leadModel = Lead::getById($leadId, app(Apps::class));

        $this->assertTrue(
            $leadModel->systemNotes->messages()->where('messages.id', $messageId)->exists(),
            'Message was not attached to the lead default channel',
        );
    }

    public function testAddMessageToLeadChannelWithExplicitChannel(): void
    {
        $lead = $this->createLeadAndGetResponse();
        $leadId = (int) $lead['data']['createLead']['id'];
        $leadModel = Lead::getById($leadId, app(Apps::class));
        $channel = $leadModel->systemNotes;
        $message = fake()->sentence();

        $response = $this->graphQL('
            mutation($input: LeadMessageInput!) {
                addMessageToLeadChannel(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'lead_id' => $leadId,
                'channel_id' => $channel->getId(),
                'message' => $message,
            ],
        ])->assertJson([
            'data' => [
                'addMessageToLeadChannel' => [
                    'message' => $message,
                ],
            ],
        ])->json();

        $messageId = (int) $response['data']['addMessageToLeadChannel']['id'];

        $this->assertTrue(
            $channel->messages()->where('messages.id', $messageId)->exists(),
            'Message was not attached to the specified channel',
        );
    }
}
