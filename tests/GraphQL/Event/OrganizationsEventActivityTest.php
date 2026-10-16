<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
use Kanvas\Guild\Organizations\Models\Organization;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

class OrganizationsEventActivityTest extends TestCase
{
    private function runEventSetup(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();
    }

    private function createOrganization(string $name): Organization
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function createPeople(string $first, string $last): People
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $dto = new PeopleData(
            app: $app,
            branch: $user->getCurrentBranch(),
            user: $user,
            firstname: $first,
            lastname: $last . uniqid(),
            contacts: Contact::collect([], DataCollection::class),
            address: Address::collect([], DataCollection::class),
        );

        return new CreatePeopleAction($dto)->execute();
    }

    /**
     * Create an event version on a controlled date and register the given people
     * as participants. Date can be in the past (we patch event_version_dates to
     * bypass createEvent's future-only UI assumption).
     *
     * @param  array<int, People>  $peoples
     */
    private function createEventVersionOn(Carbon $eventDate, array $peoples): EventVersion
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $futureDate = Carbon::now()->addWeeks(2);

        $input = [
            'name' => 'Test Event ' . uniqid(),
            'description' => 'Test',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => $futureDate->toDateString(),
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

        $versionId = (int) $createResponse->json('data.createEvent.versions.data.0.id');

        $eventVersion = EventVersion::find($versionId);
        $eventVersion->start_at = $eventDate->toDateTimeString();
        $eventVersion->saveQuietly();

        // Patch the date row directly so the canonical event_date matches what we want.
        DB::connection('event')
            ->table('event_version_dates')
            ->where('event_version_id', $versionId)
            ->update(['event_date' => $eventDate->toDateString()]);

        $participantType = ParticipantType::fromCompany($company)->fromApp($app)->first();
        $themeArea = ThemeArea::fromCompany($company)->fromApp($app)->first();

        foreach ($peoples as $people) {
            // One participant per person, as the importer keeps it: a person attending a second
            // version reuses theirs rather than colliding on the participant slug.
            $participant = Participant::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'people_id' => $people->getId(),
            ], [
                'users_id' => $user->getId(),
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

    public function testInactiveFilterReturnsOrgsWithNoParticipationInWindow(): void
    {
        $this->runEventSetup();

        // ORG-RECENT: has a person who attended last month → ACTIVE in the 2y window
        $orgRecent = $this->createOrganization('Org Recent ' . uniqid());
        $personRecent = $this->createPeople('Ana', 'Recent');
        $orgRecent->addPeople($personRecent);
        $this->createEventVersionOn(Carbon::now()->subMonth(), [$personRecent]);

        // ORG-LAPSED: only attended 3 years ago → INACTIVE in 2y window, but had prior activity
        $orgLapsed = $this->createOrganization('Org Lapsed ' . uniqid());
        $personLapsed = $this->createPeople('Beto', 'Lapsed');
        $orgLapsed->addPeople($personLapsed);
        $this->createEventVersionOn(Carbon::now()->subYears(3), [$personLapsed]);

        // ORG-NEVER: never sent anyone → INACTIVE, no prior activity
        $orgNever = $this->createOrganization('Org Never ' . uniqid());
        $personNever = $this->createPeople('Carla', 'Never');
        $orgNever->addPeople($personNever);

        $from = Carbon::now()->subYears(2)->toDateString();
        $to = Carbon::now()->toDateString();

        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                organizationsEventActivity(
                    from_date: $from
                    to_date: $to
                    activity: INACTIVE
                ) {
                    organization_id
                    organization_name
                    count
                    had_prior_activity
                }
            }
        ', ['from' => $from, 'to' => $to])->assertSuccessful();

        $rows = collect($response->json('data.organizationsEventActivity'));
        $ids = $rows->pluck('organization_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($orgLapsed->getId(), $ids, 'lapsed org should show as inactive in the 2y window');
        $this->assertContains($orgNever->getId(), $ids, 'never-active org should show as inactive');
        $this->assertNotContains($orgRecent->getId(), $ids, 'recently-active org should not show as inactive');

        $lapsedRow = $rows->firstWhere('organization_id', (string) $orgLapsed->getId());
        $this->assertSame(0, $lapsedRow['count']);
        $this->assertTrue($lapsedRow['had_prior_activity']);

        $neverRow = $rows->firstWhere('organization_id', (string) $orgNever->getId());
        $this->assertFalse($neverRow['had_prior_activity']);
    }

    public function testActiveFilterReturnsOnlyOrgsWithParticipationsInWindow(): void
    {
        $this->runEventSetup();

        $orgRecent = $this->createOrganization('Org Recent ' . uniqid());
        $personRecent = $this->createPeople('Ana', 'Recent');
        $orgRecent->addPeople($personRecent);
        $this->createEventVersionOn(Carbon::now()->subWeeks(2), [$personRecent]);

        $orgLapsed = $this->createOrganization('Org Lapsed ' . uniqid());
        $personLapsed = $this->createPeople('Beto', 'Lapsed');
        $orgLapsed->addPeople($personLapsed);
        $this->createEventVersionOn(Carbon::now()->subYears(3), [$personLapsed]);

        $from = Carbon::now()->subYears(2)->toDateString();
        $to = Carbon::now()->toDateString();

        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                organizationsEventActivity(
                    from_date: $from
                    to_date: $to
                    activity: ACTIVE
                ) { organization_id count }
            }
        ', ['from' => $from, 'to' => $to])->assertSuccessful();

        $rows = collect($response->json('data.organizationsEventActivity'));
        $ids = $rows->pluck('organization_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($orgRecent->getId(), $ids);
        $this->assertNotContains($orgLapsed->getId(), $ids);

        $recentRow = $rows->firstWhere('organization_id', (string) $orgRecent->getId());
        $this->assertGreaterThanOrEqual(1, $recentRow['count']);
    }

    /**
     * The 1-year window counts only recent activity; the two fixed windows and the year series
     * show the history behind it, so a big client that went quiet reads differently from a small one.
     */
    public function testParticipantTotalsIgnoreTheWindowAndYearsCoverIt(): void
    {
        $this->runEventSetup();

        $org = $this->createOrganization('Org History ' . uniqid());
        $regular = $this->createPeople('Ana', 'Regular');
        $newcomer = $this->createPeople('Beto', 'Newcomer');
        $former = $this->createPeople('Carla', 'Former');
        $org->addPeople($regular);
        $org->addPeople($newcomer);
        $org->addPeople($former);

        $recent = Carbon::now()->subMonth();
        $this->createEventVersionOn($recent, [$regular, $newcomer]);
        $this->createEventVersionOn(Carbon::now()->subYears(3), [$regular, $former]);

        $from = Carbon::now()->subYear();

        $response = $this->graphQL('
            query($from: Date!, $to: Date!) {
                organizationsEventActivity(from_date: $from, to_date: $to) {
                    organization_id
                    count
                    participants_last_year
                    participants_total
                    by_year { year count participants }
                }
            }
        ', ['from' => $from->toDateString(), 'to' => Carbon::now()->toDateString()])->assertSuccessful();

        $row = collect($response->json('data.organizationsEventActivity'))
            ->firstWhere('organization_id', (string) $org->getId());

        $this->assertSame(2, $row['count']);
        $this->assertSame(2, $row['participants_last_year']);
        $this->assertSame(3, $row['participants_total']);
        $this->assertSame(range($from->year, Carbon::now()->year), array_column($row['by_year'], 'year'));
        $this->assertSame(2, array_sum(array_column($row['by_year'], 'count')));

        $recentYear = collect($row['by_year'])->firstWhere('year', $recent->year);
        $this->assertSame(2, $recentYear['participants']);
    }

    public function testHistoryListsEachVersionNewestFirstWithWhoWent(): void
    {
        $this->runEventSetup();

        $org = $this->createOrganization('Org Detail ' . uniqid());
        $regular = $this->createPeople('Ana', 'Regular');
        $newcomer = $this->createPeople('Beto', 'Newcomer');
        $org->addPeople($regular);
        $org->addPeople($newcomer);

        $outsider = $this->createPeople('Carla', 'Outsider');
        $this->createOrganization('Org Other ' . uniqid())->addPeople($outsider);

        $recent = $this->createEventVersionOn(Carbon::now()->subMonth(), [$regular, $newcomer, $outsider]);
        $old = $this->createEventVersionOn(Carbon::now()->subYears(2), [$regular]);

        $history = $this->graphQL('
            query($id: ID!) {
                organizationEventHistory(organization_id: $id) {
                    event_version_id
                    registrations
                    participants
                    people { people_id name }
                }
            }
        ', ['id' => $org->getId()])->assertSuccessful()->json('data.organizationEventHistory');

        $this->assertSame(
            [(string) $recent->getId(), (string) $old->getId()],
            array_column($history, 'event_version_id')
        );
        $this->assertSame(2, $history[0]['participants'], 'the other company\'s person is not counted');
        $this->assertEqualsCanonicalizing(
            [$regular->getName(), $newcomer->getName()],
            array_column($history[0]['people'], 'name')
        );
        $this->assertSame(1, $history[1]['participants']);
    }

    public function testHistoryRefusesAnOrganizationOutsideTheTenant(): void
    {
        $response = $this->graphQL('
            query { organizationEventHistory(organization_id: 999999999) { event_version_id } }
        ');

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($response->json('data.organizationEventHistory'));
    }

    public function testTenantScopingIgnoresOrgsFromOtherCompanies(): void
    {
        $this->runEventSetup();

        $orgMine = $this->createOrganization('Mine ' . uniqid());

        // Org belonging to a different company in the same app — should never leak.
        $otherCompanyId = auth()->user()->getCurrentCompany()->getId() + 9999;
        $orgOther = Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $otherCompanyId,
            'users_id' => auth()->user()->getId(),
            'name' => 'Other Tenant ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);

        $response = $this->graphQL('
            query {
                organizationsEventActivity(activity: ALL) {
                    organization_id
                }
            }
        ')->assertSuccessful();

        $ids = collect($response->json('data.organizationsEventActivity'))
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($orgMine->getId(), $ids);
        $this->assertNotContains($orgOther->getId(), $ids, 'orgs from other companies must not appear');
    }
}
