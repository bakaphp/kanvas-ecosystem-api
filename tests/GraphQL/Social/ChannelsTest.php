<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ChannelsTest extends TestCase
{
    public function testCreateChannel()
    {
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();

        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    name,
                    description,
                    entity_id,
                    entity_namespace
                }
            }
        ', [
            'input' => $data,
        ])->assertJson([
            'data' => [
                'createSocialChannel' => [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'entity_id' => $data['entity_id'],
                    'entity_namespace' => $systemModule->model_name,
                ],
            ],
        ]);
    }

    public function testUpdateChannel()
    {
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    id
                }
            }
        ', [
            'input' => $data,
        ]);

        $data['name'] = fake()->name();
        $this->graphQL(
            '
            mutation updateSocialChannel(
                $id: ID!
                $input: SocialChannelInput!
            ){
                updateSocialChannel(id: $id, input: $input)
                {
                    name
                }
            }
        ',
            [
                'id' => $response['data']['createSocialChannel']['id'],
                'input' => $data,
            ]
        )->assertJson([
            'data' => [
                'updateSocialChannel' => [
                    'name' => $data['name'],
                ],
            ],
        ]);
    }

    public function testAttachUserToSocialChannel()
    {
        $systemModule = SystemModules::fromApp()
                        ->fromApp()
                        ->notDeleted()
                        ->firstOrFail();
        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];
        $response = $this->graphQL('
            mutation createSocialChannel(
                $input: SocialChannelInput!
            ) {
                createSocialChannel(input: $input){
                    id
                }
            }
        ', [
            'input' => $data,
        ]);
        $channelId = $response['data']['createSocialChannel']['id'];

        $response = $this->graphQL(
            '
            mutation inviteUser($input: InviteInput!) {
                inviteUser(input: $input) {
                    id,
                    invite_hash
                }
            }
        ',
            [
                'input' => [
                    'email' => fake()->email(),
                    'firstname' => fake()->name(),
                    'lastname' => fake()->name(),
                    'custom_fields' => [],
                ],
            ]
        );
        $inviteHash = $response['data']['inviteUser']['invite_hash'];

        $response = $this->graphQL(
            '
                mutation processInvite(
                    $input: CompleteInviteInput!
                ){
                    processInvite(input: $input){
                        id
                    }
                }
            ',
            [
                'input' => [
                    'invite_hash' => $inviteHash,
                    'lastname' => fake()->name(),
                    'firstname' => fake()->name(),
                    'password' => fake()->password(8),
                ],
            ]
        );
        $user = $response['data']['processInvite']['id'];
        $response = $this->graphQL(
            '
            mutation attachUserToSocialChannel(
                $input: AttachUserInput!
                ) {
                    attachUserToSocialChannel(
                        input: $input

                    ) {
                        users {
                            id
                        }
                    }
            }
        ',
            [
                'input' => [
                    'channel_id' => $channelId,
                    'user_id' => $user,
                    'roles_id' => 'Admin',
                ],
            ]
        );

        $response->assertJsonStructure([
            'data' => [
                'attachUserToSocialChannel' => [
                    'users' => [
                        0 => ['id'],
                    ],
                ],
            ],
        ]);
    }

    public function testAttachAppWideUserToSocialChannel()
    {
        $app = app(Apps::class);
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, true);

        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', ['input' => $data]);

        $channelId = $response['data']['createSocialChannel']['id'];

        $user = Users::whereHas(
            'apps',
            fn ($q) => $q->where('apps.id', $app->getId())
        )->where('id', '!=', auth()->user()->getId())
            ->notDeleted()
            ->first();

        if (! $user) {
            $this->markTestSkipped('No other app user available for this test');
        }

        $this->graphQL('
            mutation attachUserToSocialChannel($input: AttachUserInput!) {
                attachUserToSocialChannel(input: $input) {
                    users {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'channel_id' => $channelId,
                'user_id' => $user->getId(),
                'roles_id' => 'Admin',
            ],
        ])->assertJsonStructure([
            'data' => [
                'attachUserToSocialChannel' => [
                    'users' => [
                        0 => ['id'],
                    ],
                ],
            ],
        ]);

        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, false);
    }

    public function testCreateChannelWithAppWideFlag()
    {
        $app = app(Apps::class);
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, true);

        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $entityId = fake()->uuid();
        $name = fake()->name();
        $data = [
            'name' => $name,
            'description' => fake()->text(),
            'entity_id' => $entityId,
            'entity_namespace_uuid' => $systemModule->uuid,
        ];

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $data])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createSocialChannel' => [
                    'name' => $name,
                ],
            ],
        ]);

        $channelId = $response->json('data.createSocialChannel.id');

        // Creating the same channel again should return the existing one (dedup by app, slug, entity)
        $duplicateResponse = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', ['input' => $data])->assertSuccessful();

        $this->assertEquals($channelId, $duplicateResponse->json('data.createSocialChannel.id'));

        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, false);
    }

    public function testAppWideChannelMessagesFromDifferentUsers()
    {
        $app = app(Apps::class);
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, true);

        $messageType = MessageType::factory()->create();
        $channelSlug = 'app-wide-test-' . fake()->uuid();

        // User 1 (current user) creates a message in a channel
        $message1 = fake()->text();
        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'message' => $message1,
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
            ],
        ])->assertSuccessful();

        $channel = Channel::where('slug', $channelSlug)->first();
        $this->assertNotNull($channel);
        $this->assertCount(1, $channel->messages);

        // User 2 (different company) sends a message to the same channel
        $user2 = $this->createUser();
        $this->actingAs($user2, 'api');

        $message2 = fake()->text();
        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'message' => $message2,
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
            ],
        ])->assertSuccessful();

        // Both messages should be in the same channel
        $channel->refresh();
        $this->assertCount(2, $channel->messages);

        // Restore original user and clean up
        $this->actingAs(static::$cachedUser, 'api');
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, false);
    }

    public function testAppWideChannelWithEntityAndSystemModule()
    {
        $app = app(Apps::class);
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, true);

        $messageType = MessageType::factory()->create();
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->where('model_name', 'like', 'Kanvas%')
            ->firstOrFail();

        $channelSlug = 'app-wide-entity-' . fake()->uuid();
        $entityId = fake()->uuid();

        // Create the channel first with the proper entity via GraphQL
        $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'name' => $channelSlug,
                'description' => fake()->text(),
                'entity_id' => $entityId,
                'entity_namespace_uuid' => $systemModule->uuid,
                'slug' => $channelSlug,
            ],
        ])->assertSuccessful();

        $channel = Channel::where('slug', $channelSlug)
            ->where('apps_id', $app->getId())
            ->first();
        $this->assertNotNull($channel);
        $this->assertEquals($entityId, $channel->entity_id);

        // User 1 sends a message with matching entity + system_module
        $message1Text = fake()->text();
        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'message' => $message1Text,
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
                'system_modules_id' => $systemModule->id,
                'entity_id' => $entityId,
            ],
        ])->assertSuccessful();

        $channel->refresh();
        $this->assertCount(1, $channel->messages);

        // User 2 (different company) sends a message with same entity + system_module
        $user2 = $this->createUser();
        $this->actingAs($user2, 'api');

        $message2Text = fake()->text();
        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'message' => $message2Text,
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
                'system_modules_id' => $systemModule->id,
                'entity_id' => $entityId,
            ],
        ])->assertSuccessful();

        // Both messages should be in the same channel
        $channel->refresh();
        $this->assertCount(2, $channel->messages);

        // Verify only one channel exists for this slug + entity
        $channelCount = Channel::where('slug', $channelSlug)
            ->where('apps_id', $app->getId())
            ->count();
        $this->assertEquals(1, $channelCount);

        // Query channelMessages as user 2 (non-admin, different company) to verify both messages are visible
        $this->graphQL('
            query channelMessages($channel_slug: String) {
                channelMessages(channel_slug: $channel_slug) {
                    data {
                        id
                        message
                    }
                }
            }
        ', ['channel_slug' => $channelSlug])
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.channelMessages.data');

        // Restore original user and clean up
        $this->actingAs(static::$cachedUser, 'api');
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, false);
    }

    public function testChannelMessagesRestrictedWithoutAppWideFlag()
    {
        $app = app(Apps::class);
        $messageType = MessageType::factory()->create();
        $channelSlug = 'restricted-test-' . fake()->uuid();

        // With flag OFF: user 1 creates a message in a channel
        $app->set(AppEnum::ALLOW_APP_WIDE_USER_CHANNEL_ASSIGNMENT->value, false);

        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
            ],
        ])->assertSuccessful();

        $channel = Channel::where('slug', $channelSlug)
            ->where('apps_id', $app->getId())
            ->first();
        $this->assertNotNull($channel);
        $this->assertCount(1, $channel->messages);

        // User 2 (different company) sends a message with same slug — flag is OFF
        // so a separate channel is created for their company
        $user2 = $this->createUser();
        $this->actingAs($user2, 'api');

        $this->graphQL('
            mutation createMessage($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
                'channel_slug' => $channelSlug,
            ],
        ])->assertSuccessful();

        // Original channel still has only 1 message (user 2 got a separate channel)
        $channel->refresh();
        $this->assertCount(1, $channel->messages);

        // Two channels exist with the same slug (one per company)
        $channelCount = Channel::where('slug', $channelSlug)
            ->where('apps_id', $app->getId())
            ->count();
        $this->assertEquals(2, $channelCount);

        // Restore original user
        $this->actingAs(static::$cachedUser, 'api');
    }

    public function testCreateSocialChannelWithMetadataAndTags(): void
    {
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $metadata = [
            'source' => 'integration-test',
            'priority' => 5,
            'flags' => ['featured', 'pinned'],
        ];
        $tags = [
            ['name' => 'tag-' . fake()->unique()->slug(2)],
            ['name' => 'tag-' . fake()->unique()->slug(2)],
        ];

        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
            'metadata' => $metadata,
            'tags' => $tags,
        ];

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                    name
                    metadata
                }
            }
        ', ['input' => $data])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createSocialChannel' => [
                    'name' => $data['name'],
                    'metadata' => $metadata,
                ],
            ],
        ]);

        $channelId = (int) $response->json('data.createSocialChannel.id');
        $channel = Channel::findOrFail($channelId);

        $this->assertEquals($metadata, $channel->metadata);

        $persistedTagNames = $channel->tags()->pluck('name')->all();
        foreach ($tags as $tag) {
            $this->assertContains($tag['name'], $persistedTagNames);
        }
    }

    public function testUpdateSocialChannelWithMetadataAndTags(): void
    {
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $createInput = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
            'metadata' => ['version' => 1],
            'tags' => [['name' => 'initial-' . fake()->unique()->slug(2)]],
        ];

        $createResponse = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) { id }
            }
        ', ['input' => $createInput])->assertSuccessful();

        $channelId = (int) $createResponse->json('data.createSocialChannel.id');

        $newMetadata = ['version' => 2, 'note' => 'updated'];
        $newTag = 'updated-' . fake()->unique()->slug(2);
        $updateInput = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => $createInput['entity_id'],
            'entity_namespace_uuid' => $systemModule->uuid,
            'metadata' => $newMetadata,
            'tags' => [['name' => $newTag]],
        ];

        $this->graphQL('
            mutation updateSocialChannel($id: ID!, $input: SocialChannelInput!) {
                updateSocialChannel(id: $id, input: $input) {
                    id
                    metadata
                }
            }
        ', ['id' => $channelId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateSocialChannel' => [
                    'id' => (string) $channelId,
                    'metadata' => $newMetadata,
                ],
            ],
        ]);

        $channel = Channel::findOrFail($channelId);
        $this->assertEquals($newMetadata, $channel->metadata);

        $persistedTagNames = $channel->tags()->pluck('name')->all();
        $this->assertContains($newTag, $persistedTagNames);
        $this->assertNotContains($createInput['tags'][0]['name'], $persistedTagNames);
    }

    public function testDeleteSocialChannel(): void
    {
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', ['input' => $data]);

        $channelId = $response['data']['createSocialChannel']['id'];

        $this->graphQL('
            mutation deleteSocialChannel($id: ID!) {
                deleteSocialChannel(id: $id) {
                    id
                }
            }
        ', ['id' => $channelId])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteSocialChannel' => [
                    'id' => $channelId,
                ],
            ],
        ]);
    }

    public function testListSocialChannels(): void
    {
        $this->graphQL('
            query {
                socialChannels {
                    data {
                        id
                        name
                        description
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'socialChannels' => [
                    'data' => [
                        ['id', 'name', 'description'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Regression for Sentry KANVAS-ECOSYSTEM-5GS: a channel whose entity_namespace has no
     * matching SystemModule (e.g. app-wide "Agent" channels) resolved systemModule to null.
     * With `systemModule: SystemModule!` (non-null) that crashed the whole socialChannels
     * query with InvariantViolation. The field is now nullable — querying it on an orphan
     * channel must succeed and return null instead of erroring.
     */
    public function testListSocialChannelsWithOrphanSystemModuleDoesNotCrash(): void
    {
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'name' => fake()->name(),
                'description' => fake()->text(),
                'entity_id' => fake()->uuid(),
                'entity_namespace_uuid' => $systemModule->uuid,
            ],
        ])->assertSuccessful();

        $channel = Channel::findOrFail((int) $response->json('data.createSocialChannel.id'));

        // Point the channel at a namespace that maps to no SystemModule for this app,
        // reproducing the orphan state that triggered the InvariantViolation.
        $channel->entity_namespace = 'Orphan_' . substr(fake()->unique()->uuid(), 0, 8);
        $channel->saveQuietly();

        $this->assertNull($channel->systemModule, 'Test setup: channel must have no matching SystemModule');

        $this->graphQL('
            query socialChannels($where: QuerySocialChannelsWhereWhereConditions) {
                socialChannels(where: $where) {
                    data {
                        id
                        entity_namespace
                        systemModule {
                            id
                            model_name
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ENTITY_NAMESPACE',
                'operator' => 'EQ',
                'value' => $channel->entity_namespace,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'socialChannels' => [
                    'data' => [
                        [
                            'id' => (string) $channel->getId(),
                            'entity_namespace' => $channel->entity_namespace,
                            'systemModule' => null,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testDetachUserFromSocialChannel(): void
    {
        $systemModule = SystemModules::fromApp()
            ->fromApp()
            ->notDeleted()
            ->firstOrFail();

        $data = [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'entity_id' => fake()->uuid(),
            'entity_namespace_uuid' => $systemModule->uuid,
        ];

        $response = $this->graphQL('
            mutation createSocialChannel($input: SocialChannelInput!) {
                createSocialChannel(input: $input) {
                    id
                }
            }
        ', ['input' => $data]);

        $channelId = $response['data']['createSocialChannel']['id'];
        $userId = auth()->user()->getId();

        $this->graphQL('
            mutation attachUserToSocialChannel($input: AttachUserInput!) {
                attachUserToSocialChannel(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'channel_id' => $channelId,
                'user_id' => $userId,
                'roles_id' => 'Admin',
            ],
        ])->assertSuccessful();

        $this->graphQL('
            mutation detachUserToSocialChannel($channel_id: ID!, $user_id: ID!) {
                detachUserToSocialChannel(channel_id: $channel_id, user_id: $user_id) {
                    id
                }
            }
        ', [
            'channel_id' => $channelId,
            'user_id' => $userId,
        ])->assertSuccessful();
    }
}
