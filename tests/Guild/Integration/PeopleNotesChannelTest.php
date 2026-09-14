<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class PeopleNotesChannelTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social'];

    private const ADD_MESSAGE_MUTATION = '
        mutation addMessageToPeopleChannel($input: PeopleMessageInput!) {
            addMessageToPeopleChannel(input: $input) {
                id
                message
            }
        }
    ';

    private const CREATE_CHANNEL_MUTATION = '
        mutation createPeopleNotesChannel($id: ID!) {
            createPeopleNotesChannel(people_id: $id) { id name slug entity_namespace entity_id }
        }
    ';

    private const PEOPLE_CHANNELS_QUERY = '
        query people($id: Mixed) {
            peoples(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    channels { id name slug entity_namespace entity_id }
                }
            }
        }
    ';

    private const PEOPLE_NOTES_WITH_MESSAGES_QUERY = '
        query people($id: Mixed) {
            peoples(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    notes {
                        id
                        slug
                        messages { id message }
                    }
                }
            }
        }
    ';

    private const PEOPLE_NOTES_QUERY = '
        query people($id: Mixed) {
            peoples(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    notes { id name slug }
                }
            }
        }
    ';

    public function testObserverCreatesNotesChannelOnPeopleCreation(): void
    {
        $people = $this->seedPeople();

        $notes = $people->notes;

        $this->assertNotNull($notes, 'People creation must produce a notes channel');
        $this->assertSame(ChannelNameEnum::NOTES->value, $notes->name);
        $this->assertSame('people-notes-' . $people->getId(), $notes->slug);
        $this->assertSame(People::class, $notes->entity_namespace);
        $this->assertSame((string) $people->getId(), (string) $notes->entity_id);
    }

    /**
     * The agent conversation channel is replayed to the LLM as history; a human note landing in it
     * would read back as something the agent said. They must stay two distinct rows.
     */
    public function testNotesChannelIsDistinctFromTheAgentConversationChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $people = $this->seedPeople();

        $conversation = new PeopleChannelService()->findOrCreateForPeople(
            $people,
            $app,
            $user->getCurrentCompany(),
            $user
        );

        $notes = $people->refresh()->notes;

        $this->assertNotSame($conversation->getId(), $notes->getId());
        $this->assertSame('people-channel-' . $people->getId(), $conversation->slug);
        $this->assertCount(2, $people->socialChannels()->get());
    }

    public function testRecordPeopleNoteActionPostsIntoTheNotesChannel(): void
    {
        $user = auth()->user();
        $people = $this->seedPeople();

        $note = new RecordPeopleNoteAction($people)->execute('Called, left a voicemail', 'note', $user);

        $this->assertNotNull($note);
        $this->assertSame($user->getId(), (int) $note->users_id);
        $this->assertTrue(
            $people->notes->messages()->where('messages.id', $note->getId())->exists(),
            'The note must land in the people notes channel'
        );
    }

    public function testAddMessageToPeopleChannelMutationPostsIntoTheNotesChannel(): void
    {
        $people = $this->seedPeople();

        $response = $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'people_id' => (string) $people->getId(),
                'message' => 'Follow up next week',
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree();

        $messageId = (int) $response->json('data.addMessageToPeopleChannel.id');

        $this->assertGreaterThan(0, $messageId);
        $this->assertTrue(
            $people->notes->messages()->where('messages.id', $messageId)->exists()
        );
    }

    public function testMutationRejectsAChannelBelongingToSomeoneElse(): void
    {
        $people = $this->seedPeople();
        $other = $this->seedPeople();

        $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'people_id' => (string) $people->getId(),
                'message' => 'Should not land',
                'channel_id' => (string) $other->notes->getId(),
            ],
        ])->assertGraphQLErrorMessage('The channel does not belong to this person');
    }

    public function testNotesChannelIsExposedOnTheGraphQLType(): void
    {
        $people = $this->seedPeople();

        $this->graphQL(self::PEOPLE_NOTES_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJson([
                'data' => [
                    'peoples' => [
                        'data' => [
                            [
                                'id' => (string) $people->getId(),
                                'notes' => [
                                    'name' => ChannelNameEnum::NOTES->value,
                                    'slug' => 'people-notes-' . $people->getId(),
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testChannelsFieldListsTheNotesChannel(): void
    {
        $people = $this->seedPeople();

        $this->graphQL(self::PEOPLE_CHANNELS_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.peoples.data.0.channels.0.slug', 'people-notes-' . $people->getId())
            ->assertJsonPath('data.peoples.data.0.channels.0.name', ChannelNameEnum::NOTES->value)
            ->assertJsonPath('data.peoples.data.0.channels.0.entity_namespace', People::class)
            ->assertJsonPath('data.peoples.data.0.channels.0.entity_id', (string) $people->getId());
    }

    public function testChannelsFieldListsBothTheNotesAndConversationChannels(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $people = $this->seedPeople();

        new PeopleChannelService()->findOrCreateForPeople($people, $app, $user->getCurrentCompany(), $user);

        $response = $this->graphQL(self::PEOPLE_CHANNELS_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree();

        $slugs = array_column($response->json('data.peoples.data.0.channels'), 'slug');

        sort($slugs);
        $this->assertSame(
            ['people-channel-' . $people->getId(), 'people-notes-' . $people->getId()],
            $slugs
        );
    }

    public function testPostedNoteIsReadableBackThroughTheGraph(): void
    {
        $people = $this->seedPeople();

        $messageId = (int) $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'people_id' => (string) $people->getId(),
                'message' => ['content' => 'Signed the renewal today'],
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree()->json('data.addMessageToPeopleChannel.id');

        $this->graphQL(self::PEOPLE_NOTES_WITH_MESSAGES_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.peoples.data.0.notes.slug', 'people-notes-' . $people->getId())
            ->assertJsonPath('data.peoples.data.0.notes.messages.0.id', (string) $messageId)
            ->assertJsonPath('data.peoples.data.0.notes.messages.0.message.content', 'Signed the renewal today');
    }

    /**
     * The 755k people that predate the observer have no notes channel. The frontend must not have to
     * special-case them: `notes` reads null, and posting a note creates the channel on the way in.
     */
    public function testMutationSelfHealsAPersonThatHasNoNotesChannel(): void
    {
        $people = $this->seedPeople();
        $people->notes->forceDelete();
        $people->refresh();

        $this->assertNull($people->notes, 'fixture must look like a pre-observer row');
        $this->graphQL(self::PEOPLE_NOTES_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.peoples.data.0.notes', null);

        $messageId = (int) $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'people_id' => (string) $people->getId(),
                'message' => ['content' => 'First note on a legacy person'],
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree()->json('data.addMessageToPeopleChannel.id');

        $this->graphQL(self::PEOPLE_NOTES_WITH_MESSAGES_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.peoples.data.0.notes.slug', 'people-notes-' . $people->getId())
            ->assertJsonPath('data.peoples.data.0.notes.messages.0.id', (string) $messageId);
    }

    public function testCreateNotesChannelProvisionsOneWithoutPostingANote(): void
    {
        $people = $this->seedPeople();
        $people->notes->forceDelete();
        $people->refresh();
        $this->assertNull($people->notes, 'fixture must look like a pre-observer row');

        $channelId = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.createPeopleNotesChannel.slug', 'people-notes-' . $people->getId())
            ->assertJsonPath('data.createPeopleNotesChannel.name', ChannelNameEnum::NOTES->value)
            ->json('data.createPeopleNotesChannel.id');

        $this->assertSame($channelId, $people->refresh()->notes->getId());
        $this->assertCount(0, $people->notes->messages()->get(), 'provisioning must not post a note');
    }

    public function testCreateNotesChannelIsIdempotent(): void
    {
        $people = $this->seedPeople();
        $existingId = $people->notes->getId();

        $first = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()->json('data.createPeopleNotesChannel.id');
        $second = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()->json('data.createPeopleNotesChannel.id');

        $this->assertSame($existingId, $first);
        $this->assertSame($existingId, $second);
        $this->assertCount(1, $people->socialChannels()->get());
    }

    public function testBroadcastResolvesThePeopleEntityChannel(): void
    {
        $people = $this->seedPeople();
        $note = new RecordPeopleNoteAction($people)->execute('Broadcast me', 'note', auth()->user());

        $channels = new ChannelMessageCreatedEvent($people->notes, $note)->broadcastOn();

        $this->assertContains(
            'new-message-channel-people-' . $people->getId(),
            array_map(fn (Channel $channel): string => $channel->name, $channels)
        );
    }

    /**
     * The channel is created by whoever writes the first note, so that person owns the thread. Falling
     * through to the record's creator makes whoever ran the import an admin of every conversation.
     */
    public function testFirstNoteMakesTheWriterTheChannelOwnerNotTheRecordOwner(): void
    {
        $writer = auth()->user();
        $recordOwner = Users::factory()->create(['email' => 'people-owner-' . uniqid() . '@example.test']);

        $people = $this->seedPeople();
        $people->notes->forceDelete();
        $people->users_id = $recordOwner->getId();
        $people->saveQuietly();
        $people->refresh();

        $this->assertNull($people->notes, 'fixture must have no channel yet');
        $this->assertNotSame($writer->getId(), $recordOwner->getId());

        $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'people_id' => (string) $people->getId(),
                'message' => ['content' => 'First note on an imported contact'],
            ],
        ])->assertSuccessful()
            ->assertGraphQLErrorFree();

        $channel = $people->refresh()->notes;

        $this->assertNotNull($channel);
        $this->assertSame($writer->getId(), (int) $channel->users_id, 'the writer owns the channel');
        $this->assertTrue(
            $channel->users()->where('users.id', $writer->getId())->exists(),
            'the writer must be attached to channel_users'
        );
        $this->assertFalse(
            $channel->users()->where('users.id', $recordOwner->getId())->exists(),
            'the record owner must not be attached just for having created the person'
        );
    }

    private function seedPeople(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        return $people;
    }
}
