<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
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
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    protected function runEventSetup(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();
    }

    protected function createEventVersionWithParticipants(
        int $maxCapacity = 50,
        ?Carbon $eventDate = null,
        int $participantCount = 0,
    ): EventVersion {
        $this->runEventSetup();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $eventDate ??= Carbon::now()->addWeeks(2);

        $input = [
            'name' => 'Test Event ' . uniqid(),
            'description' => 'Test',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => $eventDate->toDateString(),
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
        ', ['input' => $input])->assertSuccessful();

        $eventId = (int) $createResponse->json('data.createEvent.id');
        $versionId = (int) $createResponse->json('data.createEvent.versions.data.0.id');

        $eventVersion = EventVersion::find($versionId);
        $eventVersion->start_at = $eventDate->toDateTimeString();
        $eventVersion->metadata = array_merge($eventVersion->metadata ?? [], [
            'max_capacity' => $maxCapacity,
        ]);
        $eventVersion->saveQuietly();

        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();
        $themeArea = ThemeArea::fromCompany($company)->fromApp($app)->first();

        for ($i = 0; $i < $participantCount; $i++) {
            $peopleDto = new PeopleData(
                app: $app,
                branch: $user->getCurrentBranch(),
                user: $user,
                firstname: 'Test' . $i,
                lastname: 'Attendee' . uniqid(),
                contacts: Contact::collect([], DataCollection::class),
                address: Address::collect([], DataCollection::class),
            );
            $people = (new CreatePeopleAction($peopleDto))->execute();

            $participant = Participant::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
                'people_id' => $people->getId(),
                'theme_area_id' => $themeArea->getId(),
            ]);

            EventVersionParticipant::create([
                'event_version_id' => $eventVersion->getId(),
                'participant_id' => $participant->getId(),
                'participant_type_id' => $participantType->getId(),
                'ticket_price' => 0,
                'discount' => 0,
            ]);
        }

        return $eventVersion->fresh();
    }

    public function testEventsTracking(): void
    {
        $this->createEventVersionWithParticipants(
            maxCapacity: 20,
            eventDate: Carbon::now()->addWeeks(3),
            participantCount: 5,
        );

        $this->graphQL('
            query($weeks: Int!) {
                eventsTracking(weeks_ahead: $weeks) {
                    event_version_id
                    event_name
                    event_date
                    counts
                    total_inscribed
                    goal
                    goal_percentage
                    color
                }
            }
        ', ['weeks' => 7])
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'eventsTracking' => [
                        [
                            'event_version_id',
                            'event_name',
                            'event_date',
                            'counts',
                            'total_inscribed',
                            'goal',
                            'goal_percentage',
                            'color',
                        ],
                    ],
                ],
            ]);
    }

    public function testEventsTrackingColorCoding(): void
    {
        // Create a version 1 week out with goal 20 but 0 participants — should be red
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 20,
            eventDate: Carbon::now()->addDays(5),
            participantCount: 0,
        );

        $response = $this->graphQL('
            query { eventsTracking(weeks_ahead: 2) { event_version_id color total_inscribed goal } }
        ')->assertSuccessful();

        $rows = $response->json('data.eventsTracking');
        $thisRow = collect($rows)->firstWhere('event_version_id', (string) $ev->getId());

        $this->assertNotNull($thisRow);
        $this->assertSame(0, $thisRow['total_inscribed']);
        $this->assertSame(20, $thisRow['goal']);
        $this->assertSame('red', $thisRow['color']);
    }

    public function testEventInscriptionTrack(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 50,
            eventDate: Carbon::now()->addWeeks(2),
            participantCount: 7,
        );

        $this->graphQL('
            query($id: ID!) {
                eventInscriptionTrack(event_version_id: $id) {
                    type
                    slug
                    count
                }
            }
        ', ['id' => $ev->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'eventInscriptionTrack' => [
                        ['count' => 7],
                    ],
                ],
            ]);
    }

    public function testEventInscriptionTrackEmptyForNoParticipants(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addWeeks(4),
            participantCount: 0,
        );

        $this->graphQL('
            query($id: ID!) {
                eventInscriptionTrack(event_version_id: $id) { type slug count }
            }
        ', ['id' => $ev->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'eventInscriptionTrack' => [],
                ],
            ]);
    }

    public function testEventParticipantConcentration(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 50,
            eventDate: Carbon::now()->addWeeks(3),
            participantCount: 5,
        );

        $this->graphQL('
            query($id: ID!) {
                eventParticipantConcentration(event_version_id: $id) {
                    organization_id
                    organization_name
                    count
                    percentage
                }
            }
        ', ['id' => $ev->getId()])
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'eventParticipantConcentration' => [
                        '*' => ['organization_name', 'count', 'percentage'],
                    ],
                ],
            ]);
    }

    public function testEventInscriptionsVsObjectiveReturnsFiveWeeks(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addWeeks(5),
            participantCount: 3,
        );

        $response = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsObjective(event_version_id: $id, cumulative: false) {
                    event_version_id
                    event_name
                    goal
                    weeks {
                        week
                        counts
                        total
                        objective
                    }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $weeks = $response->json('data.eventInscriptionsVsObjective.weeks');
        $this->assertCount(5, $weeks);

        $goal = $response->json('data.eventInscriptionsVsObjective.goal');
        $this->assertSame(30, $goal);

        $objectives = array_column($weeks, 'objective');
        $this->assertSame([6, 12, 18, 24, 30], $objectives);
    }

    public function testEventInscriptionsVsObjectiveCumulativeVsPerWeek(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addWeeks(5),
            participantCount: 0,
        );

        $cumResponse = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsObjective(event_version_id: $id, cumulative: true) {
                    weeks { week objective }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $pwResponse = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsObjective(event_version_id: $id, cumulative: false) {
                    weeks { week objective }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $cumWeeks = $cumResponse->json('data.eventInscriptionsVsObjective.weeks');
        $pwWeeks = $pwResponse->json('data.eventInscriptionsVsObjective.weeks');

        // Objectives (goal curve) are the same for both cumulative and per-week
        $this->assertSame(
            array_column($cumWeeks, 'objective'),
            array_column($pwWeeks, 'objective'),
        );
    }

    public function testEventInscriptionsVsHistorical(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addWeeks(3),
            participantCount: 4,
        );

        $response = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsHistorical(event_version_id: $id, cumulative: true) {
                    event_version_id
                    goal
                    weeks { week counts total objective }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $weeks = $response->json('data.eventInscriptionsVsHistorical.weeks');
        $this->assertCount(5, $weeks);

        // With no past versions, historical objective should be 0 for all weeks
        foreach ($weeks as $w) {
            $this->assertSame(0, $w['objective']);
        }
    }

    public function testEventInscriptionTrackRejectsNonexistentVersion(): void
    {
        // Try to query a version that doesn't exist — should error out
        $response = $this->graphQL('
            query($id: ID!) {
                eventInscriptionTrack(event_version_id: $id) { type count }
            }
        ', ['id' => 999999999]);

        $errors = $response->json('errors');
        $this->assertNotEmpty($errors, 'Expected GraphQL errors for nonexistent event version');
        $this->assertStringContainsString('999999999', $errors[0]['message']);
    }
}
