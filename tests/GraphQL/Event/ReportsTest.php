<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Carbon\Carbon;
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

    public function testEventsTrackingFilterByColor(): void
    {
        // Event 1: should be red (0 enrolled, goal 20, ~1 week out)
        $redEvent = $this->createEventVersionWithParticipants(
            maxCapacity: 20,
            eventDate: Carbon::now()->addDays(5),
            participantCount: 0,
        );

        $response = $this->graphQL('
            query {
                eventsTracking(weeks_ahead: 2, color: "red") {
                    event_version_id
                    color
                }
            }
        ')->assertSuccessful();

        $rows = $response->json('data.eventsTracking');
        foreach ($rows as $row) {
            $this->assertSame('red', $row['color']);
        }

        $ids = array_column($rows, 'event_version_id');
        $this->assertContains((string) $redEvent->getId(), $ids);
    }

    public function testEventsTrackingFilterBySearch(): void
    {
        $uniqueName = 'UniqueSearchTerm' . uniqid();

        $this->runEventSetup();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = [
            'name' => $uniqueName,
            'description' => 'Test',
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
            mutation($input: EventInput!) { createEvent(input: $input) { id versions { data { id } } } }
        ', ['input' => $input])->assertSuccessful();
        $vid = (int) $r->json('data.createEvent.versions.data.0.id');

        // Also create a version with different name
        $otherInput = array_merge($input, ['name' => 'OtherUnrelatedEvent' . uniqid()]);
        $this->graphQL('
            mutation($input: EventInput!) { createEvent(input: $input) { id } }
        ', ['input' => $otherInput])->assertSuccessful();

        $response = $this->graphQL('
            query($term: String!) {
                eventsTracking(weeks_ahead: 4, search: $term) {
                    event_version_id
                    event_name
                }
            }
        ', ['term' => $uniqueName])->assertSuccessful();

        $rows = $response->json('data.eventsTracking');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringContainsString($uniqueName, $row['event_name']);
        }
    }

    public function testEventsTrackingFilterByHasGoal(): void
    {
        // Create one with goal, one without
        $this->createEventVersionWithParticipants(maxCapacity: 10, eventDate: Carbon::now()->addWeeks(2), participantCount: 0);
        $noGoal = $this->createEventVersionWithParticipants(maxCapacity: 0, eventDate: Carbon::now()->addWeeks(2), participantCount: 0);

        $response = $this->graphQL('
            query {
                eventsTracking(weeks_ahead: 4, has_goal: true) {
                    event_version_id
                    goal
                }
            }
        ')->assertSuccessful();

        $rows = $response->json('data.eventsTracking');
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row['goal']);
        }

        $ids = array_column($rows, 'event_version_id');
        $this->assertNotContains((string) $noGoal->getId(), $ids);
    }

    public function testEventInscriptionsVsObjectiveExcludeTypes(): void
    {
        // Create version with participants; excluding the only type should zero out counts
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addDays(10),
            participantCount: 3,
        );

        // First call: include the type (default) to learn its slug
        $defaultResponse = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsObjective(event_version_id: $id, cumulative: true) {
                    weeks { week counts total }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $weeks = $defaultResponse->json('data.eventInscriptionsVsObjective.weeks');
        $attendeeWeek = collect($weeks)->firstWhere('total', 3);
        $this->assertNotNull($attendeeWeek, 'Expected 3 attendees in some week bucket');
        $slug = array_key_first($attendeeWeek['counts']);
        $this->assertNotNull($slug);

        // Now exclude that slug — total should drop to 0
        $excludedResponse = $this->graphQL('
            query($id: ID!, $slugs: [String!]!) {
                eventInscriptionsVsObjective(
                    event_version_id: $id
                    cumulative: true
                    exclude_types: $slugs
                ) {
                    weeks { week total }
                }
            }
        ', ['id' => $ev->getId(), 'slugs' => [$slug]])->assertSuccessful();

        $weeks = $excludedResponse->json('data.eventInscriptionsVsObjective.weeks');
        foreach ($weeks as $w) {
            $this->assertSame(0, $w['total'], 'Week ' . $w['week'] . ' should have 0 total after excluding all types');
        }
    }

    public function testEventInscriptionsVsObjectiveIncludeTypes(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 30,
            eventDate: Carbon::now()->addDays(10),
            participantCount: 4,
        );

        // Include a slug that doesn't exist — total should be 0 but counts still populated
        $response = $this->graphQL('
            query($id: ID!) {
                eventInscriptionsVsObjective(
                    event_version_id: $id
                    cumulative: true
                    include_types: ["nonexistent-slug"]
                ) {
                    weeks { week counts total }
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $weeks = $response->json('data.eventInscriptionsVsObjective.weeks');
        foreach ($weeks as $w) {
            $this->assertSame(0, $w['total'], 'Total should be 0 when include_types matches nothing');
        }
    }

    public function testEventInscriptionTrackExcludeTypes(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 50,
            eventDate: Carbon::now()->addWeeks(2),
            participantCount: 5,
        );

        // Get the slug used
        $defaultResp = $this->graphQL('
            query($id: ID!) {
                eventInscriptionTrack(event_version_id: $id) { slug count }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $slugs = array_column($defaultResp->json('data.eventInscriptionTrack'), 'slug');
        $this->assertNotEmpty($slugs);

        // Exclude all slugs — should return empty
        $filteredResp = $this->graphQL('
            query($id: ID!, $excluded: [String!]!) {
                eventInscriptionTrack(event_version_id: $id, exclude_types: $excluded) {
                    slug count
                }
            }
        ', ['id' => $ev->getId(), 'excluded' => $slugs])->assertSuccessful();

        $this->assertSame([], $filteredResp->json('data.eventInscriptionTrack'));
    }

    public function testEventParticipantConcentrationTopN(): void
    {
        $ev = $this->createEventVersionWithParticipants(
            maxCapacity: 100,
            eventDate: Carbon::now()->addWeeks(2),
            participantCount: 5,
        );

        // Without top_n all rows returned; with top_n=1 we get at most 2 rows (1 top + Other if needed)
        $response = $this->graphQL('
            query($id: ID!) {
                eventParticipantConcentration(event_version_id: $id, top_n: 1) {
                    organization_name count percentage
                }
            }
        ', ['id' => $ev->getId()])->assertSuccessful();

        $rows = $response->json('data.eventParticipantConcentration');
        $this->assertLessThanOrEqual(2, count($rows), 'top_n=1 should return at most 2 rows (top + Other)');
    }
}
