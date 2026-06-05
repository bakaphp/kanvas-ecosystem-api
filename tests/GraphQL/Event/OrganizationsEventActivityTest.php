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
