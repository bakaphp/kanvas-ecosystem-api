<?php

declare(strict_types=1);

namespace Tests\Guild;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * People relates to EventVersion through the Participant hub. Because People lives on the
 * `crm` connection and the Event models on `event`, these relations must load via separate
 * queries (not joins) — cross-database joins only work through the qualified `hasEventsVersions`
 * GraphQL handler, not a plain whereHas.
 */
class PeopleParticipantRelationTest extends TestCase
{
    public function testPeopleParticipantsRelationIsWiredThroughTheHub(): void
    {
        $relation = new People()->participants();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(Participant::class, $relation->getRelated());
        $this->assertSame('people_id', $relation->getForeignKeyName());
    }

    public function testParticipantEventVersionsRelationUsesTheEventVersionPivot(): void
    {
        $relation = new Participant()->eventVersions();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(EventVersion::class, $relation->getRelated());
        $this->assertSame('event_version_participants', $relation->getTable());
        $this->assertSame('participant_id', $relation->getForeignPivotKeyName());
        $this->assertSame('event_version_id', $relation->getRelatedPivotKeyName());
    }

    public function testPeopleTraversesToEventVersionThroughParticipant(): void
    {
        [$people, $participant, $eventVersion] = $this->createPeopleParticipatingInEvent();

        // People -> participants (crm -> event, separate query)
        $loadedParticipants = $people->participants()->get();
        $this->assertTrue(
            $loadedParticipants->contains(fn (Participant $p) => $p->getId() === $participant->getId()),
            'People::participants should contain the participant created for the person.'
        );

        // Participant -> eventVersions (same event connection, through the pivot)
        $loadedVersions = $participant->eventVersions()->get();
        $this->assertTrue(
            $loadedVersions->contains(fn (EventVersion $v) => $v->getId() === $eventVersion->getId()),
            'Participant::eventVersions should contain the event version the person is registered for.'
        );

        // Full chain People -> participant -> eventVersion resolves the created version.
        $versionIdsForPerson = $people->participants()
            ->get()
            ->flatMap(fn (Participant $p) => $p->eventVersions()->get()->map(fn (EventVersion $v) => $v->getId())->all());
        $this->assertContains($eventVersion->getId(), $versionIdsForPerson->all());

        // Reverse leg: Participant -> people points back at the person.
        $this->assertSame($people->getId(), $participant->people()->first()->getId());
    }

    public function testPeoplesQueryFiltersByEventVersionParticipation(): void
    {
        [$participatingPerson] = $this->createPeopleParticipatingInEvent();

        // The `hasEventsVersions` filter joins peoples -> participants -> event_version_participants
        // (the participant hub) and returns the people registered for an event version. Filter on
        // lastname because it is unique to the peoples table — id/uuid/companies_id also exist on the
        // joined participants table and would be ambiguous once the handler adds its join.
        $this->graphQL('
            query {
                peoples(
                    hasEventsVersions: { column: ID, operator: GT, value: 0 }
                    where: { column: LASTNAME, operator: EQ, value: "' . $participatingPerson->lastname . '" }
                ) {
                    data { uuid }
                }
            }
        ')
            ->assertOk()
            ->assertJsonCount(1, 'data.peoples.data')
            ->assertJsonPath('data.peoples.data.0.uuid', $participatingPerson->uuid);
    }

    /**
     * @return array{0: People, 1: Participant, 2: EventVersion}
     */
    private function createPeopleParticipatingInEvent(): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $eventInput = [
            'name' => 'Relation Test Event ' . uniqid(),
            'description' => 'Relation test',
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

        $createResponse = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    versions { data { id } }
                }
            }
        ', ['input' => $eventInput])->assertSuccessful();

        $versionId = (int) $createResponse->json('data.createEvent.versions.data.0.id');
        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getById($versionId, $app);

        $people = $this->createPerson();

        $participant = Participant::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'people_id' => $people->getId(),
            'theme_area_id' => ThemeArea::fromCompany($company)->fromApp($app)->first()->getId(),
        ]);

        EventVersionParticipant::create([
            'event_version_id' => $eventVersion->getId(),
            'participant_id' => $participant->getId(),
            'participant_type_id' => ParticipantType::fromCompany($company)->fromApp($app)->first()->getId(),
            'ticket_price' => 0,
            'discount' => 0,
        ]);

        return [$people, $participant, $eventVersion];
    }

    private function createPerson(): People
    {
        $user = auth()->user();

        $peopleDto = new PeopleData(
            app: app(Apps::class),
            branch: $user->getCurrentBranch(),
            user: $user,
            firstname: 'Relation',
            lastname: 'Attendee' . uniqid(),
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
        );

        return new CreatePeopleAction($peopleDto)->execute();
    }
}
