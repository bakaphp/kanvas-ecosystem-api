<?php

declare(strict_types=1);

namespace Tests\Guild\Integration\Organizations;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrganizationNotesChannelTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social'];

    private const ADD_MESSAGE_MUTATION = '
        mutation addMessageToOrganizationChannel($input: OrganizationMessageInput!) {
            addMessageToOrganizationChannel(input: $input) {
                id
                message
            }
        }
    ';

    private const CREATE_CHANNEL_MUTATION = '
        mutation createOrganizationNotesChannel($id: ID!) {
            createOrganizationNotesChannel(organization_id: $id) { id name slug entity_namespace entity_id }
        }
    ';

    private const ORGANIZATION_CHANNELS_QUERY = '
        query organizations($id: Mixed) {
            organizations(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    channels { id name slug entity_namespace entity_id }
                }
            }
        }
    ';

    private const ORGANIZATION_NOTES_WITH_MESSAGES_QUERY = '
        query organizations($id: Mixed) {
            organizations(where: { column: ID, operator: EQ, value: $id }) {
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

    private const ORGANIZATION_NOTES_QUERY = '
        query organizations($id: Mixed) {
            organizations(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    notes { id name slug }
                }
            }
        }
    ';

    public function testObserverCreatesNotesChannelOnOrganizationCreation(): void
    {
        $organization = $this->seedOrganization('Notes Channel Corp');

        $notes = $organization->notes;

        $this->assertNotNull($notes, 'Organization creation must produce a notes channel');
        $this->assertSame(ChannelNameEnum::NOTES->value, $notes->name);
        $this->assertSame('organization-notes-' . $organization->getId(), $notes->slug);
        $this->assertSame(Organization::class, $notes->entity_namespace);
        $this->assertSame((string) $organization->getId(), (string) $notes->entity_id);
    }

    public function testRecordOrganizationNoteActionPostsIntoTheNotesChannel(): void
    {
        $user = auth()->user();
        $organization = $this->seedOrganization('Note Recording Corp');

        $note = new RecordOrganizationNoteAction($organization)
            ->execute('Renewal call scheduled', 'note', $user);

        $this->assertNotNull($note);
        $this->assertSame($user->getId(), (int) $note->users_id);
        $this->assertTrue(
            $organization->notes->messages()->where('messages.id', $note->getId())->exists(),
            'The note must land in the organization notes channel'
        );
    }

    public function testAddMessageToOrganizationChannelMutationPostsIntoTheNotesChannel(): void
    {
        $organization = $this->seedOrganization('Graph Note Corp');

        $response = $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'message' => 'Contract sent for signature',
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree();

        $messageId = (int) $response->json('data.addMessageToOrganizationChannel.id');

        $this->assertGreaterThan(0, $messageId);
        $this->assertTrue(
            $organization->notes->messages()->where('messages.id', $messageId)->exists()
        );
    }

    public function testMutationRejectsAChannelBelongingToAnotherOrganization(): void
    {
        $organization = $this->seedOrganization('Owner Corp');
        $other = $this->seedOrganization('Other Corp');

        $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'message' => 'Should not land',
                'channel_id' => (string) $other->notes->getId(),
            ],
        ])->assertGraphQLErrorMessage('The channel does not belong to this organization');
    }

    public function testNotesChannelIsExposedOnTheGraphQLType(): void
    {
        $organization = $this->seedOrganization('Graph Exposed Corp');

        $this->graphQL(self::ORGANIZATION_NOTES_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJson([
                'data' => [
                    'organizations' => [
                        'data' => [
                            [
                                'id' => (string) $organization->getId(),
                                'notes' => [
                                    'name' => ChannelNameEnum::NOTES->value,
                                    'slug' => 'organization-notes-' . $organization->getId(),
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testChannelsFieldListsTheNotesChannel(): void
    {
        $organization = $this->seedOrganization('Channels Field Corp');

        $this->graphQL(self::ORGANIZATION_CHANNELS_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.organizations.data.0.channels.0.slug', 'organization-notes-' . $organization->getId())
            ->assertJsonPath('data.organizations.data.0.channels.0.name', ChannelNameEnum::NOTES->value)
            ->assertJsonPath('data.organizations.data.0.channels.0.entity_namespace', Organization::class)
            ->assertJsonPath('data.organizations.data.0.channels.0.entity_id', (string) $organization->getId());
    }

    public function testPostedNoteIsReadableBackThroughTheGraph(): void
    {
        $organization = $this->seedOrganization('Read Back Corp');

        $messageId = (int) $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'message' => ['content' => 'Master agreement countersigned'],
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree()->json('data.addMessageToOrganizationChannel.id');

        $this->graphQL(self::ORGANIZATION_NOTES_WITH_MESSAGES_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.organizations.data.0.notes.slug', 'organization-notes-' . $organization->getId())
            ->assertJsonPath('data.organizations.data.0.notes.messages.0.id', (string) $messageId)
            ->assertJsonPath('data.organizations.data.0.notes.messages.0.message.content', 'Master agreement countersigned');
    }

    /**
     * Organizations created before the observer have no notes channel. `notes` reads null and the
     * mutation creates it on first write, so the frontend needs no separate provisioning call.
     */
    public function testMutationSelfHealsAnOrganizationThatHasNoNotesChannel(): void
    {
        $organization = $this->seedOrganization('Legacy Corp');
        $organization->notes->forceDelete();
        $organization->refresh();

        $this->assertNull($organization->notes, 'fixture must look like a pre-observer row');
        $this->graphQL(self::ORGANIZATION_NOTES_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.organizations.data.0.notes', null);

        $messageId = (int) $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'message' => ['content' => 'First note on a legacy organization'],
            ],
        ])->assertSuccessful()
->assertGraphQLErrorFree()->json('data.addMessageToOrganizationChannel.id');

        $this->graphQL(self::ORGANIZATION_NOTES_WITH_MESSAGES_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.organizations.data.0.notes.slug', 'organization-notes-' . $organization->getId())
            ->assertJsonPath('data.organizations.data.0.notes.messages.0.id', (string) $messageId);
    }

    public function testCreateNotesChannelProvisionsOneWithoutPostingANote(): void
    {
        $organization = $this->seedOrganization('Provision Corp');
        $organization->notes->forceDelete();
        $organization->refresh();
        $this->assertNull($organization->notes, 'fixture must look like a pre-observer row');

        $channelId = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.createOrganizationNotesChannel.slug', 'organization-notes-' . $organization->getId())
            ->assertJsonPath('data.createOrganizationNotesChannel.name', ChannelNameEnum::NOTES->value)
            ->json('data.createOrganizationNotesChannel.id');

        $this->assertSame($channelId, $organization->refresh()->notes->getId());
        $this->assertCount(0, $organization->notes->messages()->get(), 'provisioning must not post a note');
    }

    public function testCreateNotesChannelIsIdempotent(): void
    {
        $organization = $this->seedOrganization('Idempotent Corp');
        $existingId = $organization->notes->getId();

        $first = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()->json('data.createOrganizationNotesChannel.id');
        $second = (int) $this->graphQL(self::CREATE_CHANNEL_MUTATION, ['id' => (string) $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()->json('data.createOrganizationNotesChannel.id');

        $this->assertSame($existingId, $first);
        $this->assertSame($existingId, $second);
        $this->assertCount(1, $organization->socialChannels()->get());
    }

    /**
     * broadcastOn() maps entity_namespace through a hardcoded slug table that throws on a miss, so an
     * entity missing from it fails the broadcast job for every note posted to its channel.
     */
    public function testBroadcastResolvesTheOrganizationEntityChannel(): void
    {
        $organization = $this->seedOrganization('Broadcast Corp');
        $note = new RecordOrganizationNoteAction($organization)
            ->execute('Broadcast me', 'note', auth()->user());

        $channels = new ChannelMessageCreatedEvent($organization->notes, $note)->broadcastOn();

        $this->assertContains(
            'new-message-channel-organization-' . $organization->getId(),
            array_map(fn (Channel $channel): string => $channel->name, $channels)
        );
    }

    /**
     * The channel is created by whoever writes the first note, so that person owns the thread. It used
     * to fall through to the entity's creator, which made the record's owner admin of a conversation
     * someone else started — and on an imported account that owner is whoever ran the import.
     */
    public function testFirstNoteMakesTheWriterTheChannelOwnerNotTheRecordOwner(): void
    {
        $writer = auth()->user();
        $recordOwner = Users::factory()->create(['email' => 'record-owner-' . uniqid() . '@example.test']);

        $organization = $this->seedOrganization('Owner Attribution Corp');
        $organization->notes->forceDelete();
        $organization->users_id = $recordOwner->getId();
        $organization->saveQuietly();
        $organization->refresh();

        $this->assertNull($organization->notes, 'fixture must have no channel yet');
        $this->assertNotSame($writer->getId(), $recordOwner->getId());

        $this->graphQL(self::ADD_MESSAGE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'message' => ['content' => 'First note on an imported account'],
            ],
        ])->assertSuccessful()->assertGraphQLErrorFree();

        $channel = $organization->refresh()->notes;

        $this->assertNotNull($channel);
        $this->assertSame($writer->getId(), (int) $channel->users_id, 'the writer owns the channel');
        $this->assertTrue(
            $channel->users()->where('users.id', $writer->getId())->exists(),
            'the writer must be attached to channel_users'
        );
        $this->assertFalse(
            $channel->users()->where('users.id', $recordOwner->getId())->exists(),
            'the record owner must not be attached just for having created the organization'
        );
    }

    private function seedOrganization(string $name): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name . ' ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
