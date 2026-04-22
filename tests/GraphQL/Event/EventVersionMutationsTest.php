<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class EventVersionMutationsTest extends TestCase
{
    protected function runEventSetup(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
    }

    protected function createBaseEvent(): array
    {
        $this->runEventSetup();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = [
            'name' => 'Base Event ' . uniqid(),
            'description' => 'Base event for version mutation tests',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => Carbon::now()->addWeeks(2)->toDateString(),
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                ],
            ],
        ];

        $r = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id versions { data { id } } }
            }
        ', ['input' => $input])->assertSuccessful();

        return [
            'event_id' => $r->json('data.createEvent.id'),
            'version_id' => $r->json('data.createEvent.versions.data.0.id'),
        ];
    }

    public function testCreateEventVersion(): void
    {
        ['event_id' => $eventId] = $this->createBaseEvent();

        $input = [
            'event_id' => $eventId,
            'name' => 'Version 2',
            'description' => 'Second instance of this event',
            'price_per_ticket' => 250.0,
            'max_capacity' => 50,
            'start_at' => Carbon::now()->addWeeks(6)->toDateTimeString(),
            'end_at' => Carbon::now()->addWeeks(6)->addHours(8)->toDateTimeString(),
            'dates' => [
                [
                    'date' => Carbon::now()->addWeeks(6)->toDateString(),
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ],
            ],
        ];

        $r = $this->graphQL('
            mutation($input: EventVersionInput!) {
                createEventVersion(input: $input) {
                    id
                    name
                    version_number
                    price_per_ticket
                    dates { date start_time end_time }
                }
            }
        ', ['input' => $input]);

        if ($r->json('errors') !== null) {
            $this->fail('GraphQL errors: ' . json_encode($r->json('errors')));
        }

        $this->assertSame('Version 2', $r->json('data.createEventVersion.name'));
        $this->assertSame(2, $r->json('data.createEventVersion.version_number'));
        $this->assertCount(1, $r->json('data.createEventVersion.dates'));
    }

    public function testCreateEventVersionWithTagsAndCustomFields(): void
    {
        ['event_id' => $eventId] = $this->createBaseEvent();

        $input = [
            'event_id' => $eventId,
            'name' => 'Tagged Version',
            'price_per_ticket' => 100.0,
            'max_capacity' => 20,
            'tags' => [['name' => 'premium'], ['name' => 'vip']],
            'custom_fields' => [
                ['name' => 'internal_code', 'data' => 'V2-2026'],
                ['name' => 'sponsor', 'data' => 'AcmeCorp'],
            ],
        ];

        $r = $this->graphQL('
            mutation($input: EventVersionInput!) {
                createEventVersion(input: $input) {
                    id
                    name
                    tags { data { name } }
                    custom_fields { data { name } }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $versionId = $r->json('data.createEventVersion.id');
        $tagNames = array_column($r->json('data.createEventVersion.tags.data'), 'name');
        $this->assertContains('premium', $tagNames);
        $this->assertContains('vip', $tagNames);

        $cfNames = array_column($r->json('data.createEventVersion.custom_fields.data'), 'name');
        $this->assertContains('internal_code', $cfNames);
    }

    public function testUpdateEventVersion(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();

        $input = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'price_per_ticket' => 500.0,
            'max_capacity' => 100,
        ];

        $r = $this->graphQL('
            mutation($id: ID!, $input: EventVersionUpdateInput!) {
                updateEventVersion(id: $id, input: $input) {
                    id
                    name
                    description
                    price_per_ticket
                    max_capacity
                    metadata
                }
            }
        ', ['id' => $versionId, 'input' => $input])->assertSuccessful();

        $this->assertSame('Updated Name', $r->json('data.updateEventVersion.name'));
        $this->assertSame('Updated description', $r->json('data.updateEventVersion.description'));
        $this->assertSame(100, $r->json('data.updateEventVersion.max_capacity'));
        $metadata = $r->json('data.updateEventVersion.metadata');
        $this->assertSame(100, $metadata['max_capacity']);
    }

    public function testUpdateEventVersionTopLevelMaxCapacityBeatsNestedMetadata(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();

        // Mirror a common frontend mistake: the client echoes back a stale
        // metadata.max_capacity while the user edits the top-level field. The
        // top-level `max_capacity: 85` must win over `metadata.max_capacity: 80`.
        $input = [
            'max_capacity' => 85,
            'metadata' => [
                'max_capacity' => 80,
                'has_book' => false,
                'has_forum' => true,
            ],
        ];

        $r = $this->graphQL('
            mutation($id: ID!, $input: EventVersionUpdateInput!) {
                updateEventVersion(id: $id, input: $input) {
                    id
                    max_capacity
                    metadata
                }
            }
        ', ['id' => $versionId, 'input' => $input])->assertSuccessful();

        $this->assertSame(85, $r->json('data.updateEventVersion.max_capacity'));
        $metadata = $r->json('data.updateEventVersion.metadata');
        $this->assertSame(85, $metadata['max_capacity']);
        $this->assertSame(true, $metadata['has_forum']);      // unrelated metadata keys preserved
        $this->assertSame(false, $metadata['has_book']);
    }

    public function testDeleteEventVersion(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();

        $r = $this->graphQL('
            mutation($id: ID!) { deleteEventVersion(id: $id) }
        ', ['id' => $versionId])->assertSuccessful();

        $this->assertTrue($r->json('data.deleteEventVersion'));
    }

    public function testAddPeopleToEventVersionWithEnhancedFields(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        // Create a person
        $peopleDto = new PeopleData(
            app: $app,
            branch: $user->getCurrentBranch(),
            user: $user,
            firstname: 'Test',
            lastname: 'Attendee' . uniqid(),
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
        );
        $people = new CreatePeopleAction($peopleDto)->execute();

        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();

        $input = [
            'people_id' => $people->getId(),
            'event_version_id' => $versionId,
            'ticket_price' => 150.0,
            'discount' => 10.0,
            'participant_type_id' => $participantType->getId(),
            'tags' => [['name' => 'early-bird']],
            'custom_fields' => [
                ['name' => 'seat_preference', 'data' => 'front'],
            ],
        ];

        $r = $this->graphQL('
            mutation($input: PeopleEventVersionInput!) {
                addPeopleToEventVersion(input: $input) {
                    id
                }
            }
        ', ['input' => $input]);

        if ($r->json('errors') !== null) {
            $this->fail('GraphQL errors: ' . json_encode($r->json('errors')));
        }

        $this->assertNotNull($r->json('data.addPeopleToEventVersion.id'));

        // Verify EVP record has the participant_type + pricing
        $evp = EventVersionParticipant::where('event_version_id', $versionId)->first();
        $this->assertNotNull($evp);
        $this->assertSame($participantType->getId(), (int) $evp->participant_type_id);
        $this->assertSame(150.0, (float) $evp->ticket_price);
    }

    public function testUpdateEventVersionParticipant(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $peopleDto = new PeopleData(
            app: $app,
            branch: $user->getCurrentBranch(),
            user: $user,
            firstname: 'Test',
            lastname: 'Update' . uniqid(),
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
        );
        $people = new CreatePeopleAction($peopleDto)->execute();
        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();

        $this->graphQL('
            mutation($input: PeopleEventVersionInput!) {
                addPeopleToEventVersion(input: $input) { id }
            }
        ', ['input' => [
            'people_id' => $people->getId(),
            'event_version_id' => $versionId,
            'ticket_price' => 100.0,
            'discount' => 0.0,
            'participant_type_id' => $participantType->getId(),
        ]])->assertSuccessful();

        $evp = EventVersionParticipant::where('event_version_id', $versionId)->first();
        $this->assertNotNull($evp);

        // Now update — bump price, add discount
        $r = $this->graphQL('
            mutation($id: ID!, $input: EventVersionParticipantUpdateInput!) {
                updateEventVersionParticipant(id: $id, input: $input) {
                    id
                    ticket_price
                    discount
                }
            }
        ', [
            'id' => $evp->getId(),
            'input' => [
                'ticket_price' => 200.0,
                'discount' => 25.0,
            ],
        ])->assertSuccessful();

        $updated = EventVersionParticipant::find($evp->getId());
        $this->assertSame(200.0, (float) $updated->ticket_price);
        $this->assertSame(25.0, (float) $updated->discount);
    }

    public function testFollowAndUnfollowEventVersion(): void
    {
        ['version_id' => $versionId] = $this->createBaseEvent();
        $user = auth()->user();

        $ev = EventVersion::find($versionId);

        $follow = $this->graphQL('
            mutation($input: FollowInput!) {
                followEventVersion(input: $input)
            }
        ', [
            'input' => [
                'user_id' => $user->getId(),
                'entity_id' => $ev->uuid,
            ],
        ])->assertSuccessful();

        $this->assertTrue($follow->json('data.followEventVersion'));

        $unfollow = $this->graphQL('
            mutation($input: FollowInput!) {
                unFollowEventVersion(input: $input)
            }
        ', [
            'input' => [
                'user_id' => $user->getId(),
                'entity_id' => $ev->uuid,
            ],
        ])->assertSuccessful();

        $this->assertTrue($unfollow->json('data.unFollowEventVersion'));
    }
}
