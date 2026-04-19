<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class EventAdvancedMutationsTest extends TestCase
{
    protected function runEventSetup(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
    }

    protected function createBaseVersion(): array
    {
        $this->runEventSetup();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = [
            'name' => 'Adv Event ' . uniqid(),
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [[
                'date' => Carbon::now()->addWeeks(2)->toDateString(),
                'start_time' => '10:00',
                'end_time' => '12:00',
            ]],
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

    protected function createPerson(string $suffix = ''): int
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $dto = new PeopleData(
            app: $app,
            branch: $user->getCurrentBranch(),
            user: $user,
            firstname: 'Test' . $suffix,
            lastname: 'Person' . uniqid(),
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
        );

        return (int) new CreatePeopleAction($dto)->execute()->getId();
    }

    public function testAddUpdateDeleteEventVersionDate(): void
    {
        ['version_id' => $versionId] = $this->createBaseVersion();

        $add = $this->graphQL('
            mutation($input: AddEventVersionDateInput!) {
                addEventVersionDate(input: $input) {
                    id
                    date
                    start_time
                    end_time
                }
            }
        ', ['input' => [
            'event_version_id' => $versionId,
            'date' => Carbon::now()->addWeeks(3)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]])->assertSuccessful();

        $dateId = $add->json('data.addEventVersionDate.id');
        $this->assertNotNull($dateId);

        $update = $this->graphQL('
            mutation($id: ID!, $input: UpdateEventVersionDateInput!) {
                updateEventVersionDate(id: $id, input: $input) {
                    id
                    start_time
                    end_time
                }
            }
        ', ['id' => $dateId, 'input' => ['start_time' => '08:30', 'end_time' => '18:00']])
            ->assertSuccessful();
        $this->assertStringStartsWith('08:30', (string) $update->json('data.updateEventVersionDate.start_time'));
        $this->assertStringStartsWith('18:00', (string) $update->json('data.updateEventVersionDate.end_time'));

        $this->graphQL('
            mutation($id: ID!) { deleteEventVersionDate(id: $id) }
        ', ['id' => $dateId])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteEventVersionDate' => true]]);

        $this->assertNull(EventVersionDate::find($dateId));
    }

    public function testFacilitatorCrudAndAttachDetach(): void
    {
        ['version_id' => $versionId] = $this->createBaseVersion();
        $peopleId = $this->createPerson('Facilitator');

        $create = $this->graphQL('
            mutation($input: FacilitatorInput!) {
                createFacilitator(input: $input) { id description }
            }
        ', ['input' => [
            'people_id' => $peopleId,
            'description' => 'Keynote speaker',
            'identification' => 'ID-42',
        ]])->assertSuccessful();

        $facilitatorId = $create->json('data.createFacilitator.id');
        $this->assertNotNull($facilitatorId);

        $this->graphQL('
            mutation($id: ID!, $input: FacilitatorUpdateInput!) {
                updateFacilitator(id: $id, input: $input) { id description }
            }
        ', ['id' => $facilitatorId, 'input' => ['description' => 'Updated bio']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateFacilitator' => ['description' => 'Updated bio']]]);

        $attach = $this->graphQL('
            mutation($input: FacilitatorEventVersionInput!) {
                attachFacilitatorToEventVersion(input: $input) { id }
            }
        ', ['input' => [
            'event_version_id' => $versionId,
            'facilitator_id' => $facilitatorId,
        ]])->assertSuccessful();

        $pivotId = $attach->json('data.attachFacilitatorToEventVersion.id');
        $this->assertNotNull($pivotId);
        $this->assertNotNull(EventVersionFacilitator::find($pivotId));

        $this->graphQL('
            mutation($input: FacilitatorEventVersionInput!) {
                detachFacilitatorFromEventVersion(input: $input)
            }
        ', ['input' => [
            'event_version_id' => $versionId,
            'facilitator_id' => $facilitatorId,
        ]])
            ->assertSuccessful()
            ->assertJson(['data' => ['detachFacilitatorFromEventVersion' => true]]);

        $this->graphQL('
            mutation($id: ID!) { deleteFacilitator(id: $id) }
        ', ['id' => $facilitatorId])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteFacilitator' => true]]);

        $this->assertNull(Facilitator::find($facilitatorId));
    }

    public function testCopyParticipantsToEventVersion(): void
    {
        ['event_id' => $eventId, 'version_id' => $sourceId] = $this->createBaseVersion();

        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();

        // Create a target version under the same event
        $target = $this->graphQL('
            mutation($input: EventVersionInput!) {
                createEventVersion(input: $input) { id }
            }
        ', ['input' => [
            'event_id' => $eventId,
            'name' => 'Target Version',
            'price_per_ticket' => 0,
            'max_capacity' => 10,
        ]])->assertSuccessful();
        $targetId = $target->json('data.createEventVersion.id');

        // Add 2 people to source version
        foreach (['A', 'B'] as $suffix) {
            $peopleId = $this->createPerson($suffix);
            $this->graphQL('
                mutation($input: PeopleEventVersionInput!) {
                    addPeopleToEventVersion(input: $input) { id }
                }
            ', ['input' => [
                'people_id' => $peopleId,
                'event_version_id' => $sourceId,
                'ticket_price' => 150.0,
                'discount' => 0.0,
                'participant_type_id' => $participantType->getId(),
            ]])->assertSuccessful();
        }

        $this->assertSame(
            2,
            EventVersionParticipant::where('event_version_id', $sourceId)->where('is_deleted', 0)->count(),
        );

        $copy = $this->graphQL('
            mutation($input: CopyParticipantsInput!) {
                copyParticipantsToEventVersion(input: $input)
            }
        ', ['input' => [
            'from_event_version_id' => $sourceId,
            'to_event_version_id' => $targetId,
        ]])->assertSuccessful();

        $this->assertSame(2, (int) $copy->json('data.copyParticipantsToEventVersion'));
        $this->assertSame(
            2,
            EventVersionParticipant::where('event_version_id', $targetId)->where('is_deleted', 0)->count(),
        );

        // Re-running should be idempotent (no new copies, nothing restored)
        $copy2 = $this->graphQL('
            mutation($input: CopyParticipantsInput!) {
                copyParticipantsToEventVersion(input: $input)
            }
        ', ['input' => [
            'from_event_version_id' => $sourceId,
            'to_event_version_id' => $targetId,
        ]])->assertSuccessful();
        $this->assertSame(0, (int) $copy2->json('data.copyParticipantsToEventVersion'));
    }

    public function testPaymentStatusOnEventVersionParticipant(): void
    {
        ['version_id' => $versionId] = $this->createBaseVersion();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();
        $peopleId = $this->createPerson('Payer');

        $this->graphQL('
            mutation($input: PeopleEventVersionInput!) {
                addPeopleToEventVersion(input: $input) { id }
            }
        ', ['input' => [
            'people_id' => $peopleId,
            'event_version_id' => $versionId,
            'ticket_price' => 100.0,
            'discount' => 0.0,
            'payment_status' => 'pending',
            'participant_type_id' => $participantType->getId(),
        ]])->assertSuccessful();

        $evp = EventVersionParticipant::where('event_version_id', $versionId)->first();
        $this->assertNotNull($evp);
        $this->assertSame('pending', $evp->payment_status);

        $this->graphQL('
            mutation($id: ID!, $input: EventVersionParticipantUpdateInput!) {
                updateEventVersionParticipant(id: $id, input: $input) {
                    id
                    payment_status
                }
            }
        ', ['id' => $evp->getId(), 'input' => ['payment_status' => 'paid']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateEventVersionParticipant' => ['payment_status' => 'paid']]]);
    }

    public function testParticipantCrud(): void
    {
        $this->runEventSetup();
        $peopleId = $this->createPerson('Participant');

        $create = $this->graphQL('
            mutation($input: ParticipantInput!) {
                createParticipant(input: $input) {
                    id
                    uuid
                    is_prospect
                    people { id }
                }
            }
        ', ['input' => [
            'people_id' => $peopleId,
            'is_prospect' => true,
            'general_representative' => 'Jane Doe',
            'tags' => [['name' => 'vip']],
            'custom_fields' => [
                ['name' => 'internal_code', 'data' => 'P-001'],
            ],
        ]])->assertSuccessful();

        $participantId = $create->json('data.createParticipant.id');
        $this->assertNotNull($participantId);
        $this->assertTrue((bool) $create->json('data.createParticipant.is_prospect'));
        $this->assertSame((string) $peopleId, (string) $create->json('data.createParticipant.people.id'));

        $update = $this->graphQL('
            mutation($id: ID!, $input: ParticipantUpdateInput!) {
                updateParticipant(id: $id, input: $input) {
                    id
                    is_prospect
                }
            }
        ', ['id' => $participantId, 'input' => ['is_prospect' => false]])
            ->assertSuccessful();
        $this->assertFalse((bool) $update->json('data.updateParticipant.is_prospect'));

        $this->graphQL('
            mutation($id: ID!) { deleteParticipant(id: $id) }
        ', ['id' => $participantId])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteParticipant' => true]]);

        $this->assertNull(Participant::find($participantId));
    }

    public function testEventVersionExposesCurrency(): void
    {
        ['version_id' => $versionId] = $this->createBaseVersion();

        $r = $this->graphQL('
            query($id: Mixed!) {
                eventVersions(where: { column: ID, value: $id }) {
                    data { id currency { id code } }
                }
            }
        ', ['id' => $versionId])->assertSuccessful();

        // Version may or may not have a currency assigned depending on seed; just ensure the field resolves.
        $this->assertArrayHasKey('currency', $r->json('data.eventVersions.data.0'));
    }
}
