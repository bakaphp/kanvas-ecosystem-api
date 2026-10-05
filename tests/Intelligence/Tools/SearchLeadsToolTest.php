<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use Tests\TestCase;

class SearchLeadsToolTest extends TestCase
{
    public function testFindsLeadByContactName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Zeraphina' . uniqid();
        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => $token,
            'lastname' => 'Quill',
        ]);
        $match = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Deal for ' . $token,
            'people_id' => $people->getId(),
            'status' => 0,
        ]);
        $other = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => 'Unrelated lead ' . uniqid(),
            'status' => 0,
        ]);

        $result = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, limit: 100);

        $ids = array_column($result['leads'], 'lead_id');
        $this->assertContains($match->getId(), $ids);
        $this->assertNotContains($other->getId(), $ids);
    }

    public function testFindsLeadByTitleAndRespectsStatusFilter(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Vulcanox' . uniqid();
        $openLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => $token . ' open',
            'status' => 0,
        ]);
        $closedLead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => $token . ' closed',
            'leads_status_id' => self::lostStatusId(),
        ]);

        $openOnly = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'open', limit: 100);
        $openIds = array_column($openOnly['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $openIds);
        $this->assertNotContains($closedLead->getId(), $openIds);

        $closedOnly = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'closed', limit: 100);
        $this->assertSame([$closedLead->getId()], array_column($closedOnly['leads'], 'lead_id'), 'closed is the named status, not the unused integer column');
        $this->assertSame('Lost', $closedOnly['leads'][0]['status'], 'The CRM status is reported by name so a lost lead is never described as active');
        $this->assertFalse($closedOnly['leads'][0]['is_open']);

        $all = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'all', limit: 100);
        $allIds = array_column($all['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $allIds);
        $this->assertContains($closedLead->getId(), $allIds);
    }

    private static function lostStatusId(): int
    {
        return LeadStatus::query()->where('name', 'Lost')->where('apps_id', 0)->firstOrCreate(
            ['name' => 'Lost', 'apps_id' => 0, 'companies_id' => 0],
            ['is_default' => 0]
        )->getId();
    }

    /**
     * The regression that matters most on real data: 384 people have no `Email` row and a working
     * address under one of the other three types. Matching only the first type reports every one of
     * them as uncontactable, which sends someone chasing details already on file.
     */
    public function testAPersonReachableOnlyAtASecondaryAddressIsNotMissingAnEmail(): void
    {
        $token = 'Secondaryonly' . uniqid();
        $lead = $this->leadWithContacts($token, [
            ContactTypeEnum::SECONDARY_EMAIL->value => $token . '@example.com',
        ]);

        $ids = $this->auditIds('email');

        $this->assertNotContains($lead->getId(), $ids);
    }

    public function testALeadWithNoEmailIsReportedMissingOne(): void
    {
        $token = 'Nomail' . uniqid();
        $lead = $this->leadWithContacts($token, [
            ContactTypeEnum::CELLPHONE->value => '18095551234',
        ]);

        $this->assertContains($lead->getId(), $this->auditIds('email'));
        $this->assertNotContains($lead->getId(), $this->auditIds('phone'));
    }

    /** A contact row with an empty value is not a way to reach anyone. */
    public function testABlankContactValueCountsAsMissing(): void
    {
        $token = 'Blankmail' . uniqid();
        $lead = $this->leadWithContacts($token, [
            ContactTypeEnum::EMAIL->value => '   ',
        ]);

        $this->assertContains($lead->getId(), $this->auditIds('email'));
    }

    /** "both" is the uncontactable set, so a lead missing only one side must not appear in it. */
    public function testBothMatchesOnlyLeadsMissingEveryContact(): void
    {
        $reachable = $this->leadWithContacts('Halfreach' . uniqid(), [
            ContactTypeEnum::EMAIL->value => 'half' . uniqid() . '@example.com',
        ]);
        $unreachable = $this->leadWithContacts('Noreach' . uniqid(), []);

        $both = $this->auditIds('both');

        $this->assertContains($unreachable->getId(), $both);
        $this->assertNotContains($reachable->getId(), $both);
        $this->assertContains($reachable->getId(), $this->auditIds('either'));
    }

    /** The count is the answer to "how many"; the list is only evidence, so limit must not cap it. */
    public function testTotalMatchingIsNotCappedByLimit(): void
    {
        $this->leadWithContacts('Countone' . uniqid(), []);
        $this->leadWithContacts('Counttwo' . uniqid(), []);

        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(missing_contact: 'both', status: 'all', limit: 1);

        $this->assertSame(1, $result['count']);
        $this->assertGreaterThan(1, $result['total_matching']);
        $this->assertTrue($result['truncated']);
    }

    public function testAnAuditRowCarriesTheAddressSoItCanBeJudged(): void
    {
        $token = 'Shownemail' . uniqid();
        $email = strtolower($token) . '@example.com';
        $this->leadWithContacts($token, [ContactTypeEnum::EMAIL->value => $email]);

        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(query: $token, status: 'all', limit: 5);

        $this->assertSame($email, $result['leads'][0]['email']);
        $this->assertArrayHasKey('phone', $result['leads'][0]);
    }

    public function testAnUnknownMissingContactValueIsRejectedWithTheAllowedOnes(): void
    {
        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(missing_contact: 'fax');

        $this->assertStringContainsString('"email", "phone", "either" or "both"', $result['error']);
    }

    public function testSearchingWithNeitherAQueryNorAnAuditFilterIsRefused(): void
    {
        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke();

        $this->assertSame(0, $result['total_matching']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testUpdatedSinceTodayExcludesLeadsUntouchedToday(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $token = 'Freshlead' . uniqid();
        $fresh = $this->leadWithContacts($token, []);
        $stale = $this->leadWithContacts('Stalelead' . uniqid(), []);
        $this->forceUpdatedAt($stale, Carbon::now()->subDays(5));

        $result = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(status: 'all', updated_since: 'today', limit: 100);

        $ids = array_column($result['leads'], 'lead_id');
        $this->assertContains($fresh->getId(), $ids);
        $this->assertNotContains($stale->getId(), $ids);
    }

    /**
     * The bug this fixes: `updated_at` is stored UTC, so a UTC-day filter files the 10pm work of a
     * UTC-4 rep under the next day. Yesterday is where that actually bites — local yesterday 10pm is
     * already today in UTC, so the naive filter drops it out of "yesterday" and the rep's evening
     * simply disappears from the report.
     */
    public function testYesterdayIsTheCompanysDayNotTheUtcOne(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $original = $company->timezone;
        $company->timezone = 'America/Santo_Domingo';
        $company->saveQuietly();

        try {
            $lateLocal = Carbon::now('America/Santo_Domingo')->subDay()->startOfDay()->addHours(22);
            $utc = $lateLocal->copy()->utc();

            $this->assertNotSame(
                $lateLocal->toDateString(),
                $utc->toDateString(),
                'The fixture must straddle the UTC date boundary or it proves nothing.',
            );

            $lead = $this->leadWithContacts('Eveninglead' . uniqid(), []);
            $this->forceUpdatedAt($lead, $utc);

            $result = new SearchLeadsTool()
                ->withContext($app, $company, $user)
                ->__invoke(status: 'all', updated_since: 'yesterday', limit: 100);

            $this->assertContains($lead->getId(), array_column($result['leads'], 'lead_id'));
        } finally {
            $company->timezone = $original;
            $company->saveQuietly();
        }
    }

    /** "which leads did <rep> touch today" was refused outright before the date filter existed. */
    public function testOwnerAloneIsAnAcceptableFilter(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $mine = $this->leadWithContacts('Ownedlead' . uniqid(), []);
        $mine->leads_owner_id = $user->getId();
        $mine->saveQuietly();

        $result = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(status: 'all', owner: $user->email, limit: 100);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertContains($mine->getId(), array_column($result['leads'], 'lead_id'));
    }

    public function testAnUnparseableUpdatedSinceIsRejectedWithTheAllowedForms(): void
    {
        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(updated_since: 'last tuesday');

        $this->assertStringContainsString('updated_since', $result['error']);
        $this->assertStringContainsString('today', $result['error']);
    }

    public function testUpdatedUntilIsInclusiveOfTheWholeDay(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $timezone = $company->getTimezone() ?? 'UTC';
        $day = Carbon::now($timezone)->subDays(3);

        $lead = $this->leadWithContacts('Boundedlead' . uniqid(), []);
        $this->forceUpdatedAt($lead, $day->copy()->startOfDay()->addHours(23)->addMinutes(59)->utc());

        $result = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(
                status: 'all',
                updated_since: $day->format('Y-m-d'),
                updated_until: $day->format('Y-m-d'),
                limit: 100,
            );

        $this->assertContains($lead->getId(), array_column($result['leads'], 'lead_id'));
    }

    /**
     * `updated_at` is managed by Eloquent, so a save would stamp now over whatever the test needs.
     */
    private function forceUpdatedAt(Lead $lead, Carbon $moment): void
    {
        Lead::query()->where('id', $lead->getId())->update(['updated_at' => $moment->utc()]);
    }

    /**
     * @param array<int, string> $contacts Keyed by contact type id.
     */
    private function leadWithContacts(string $token, array $contacts): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'firstname' => $token,
            'lastname' => 'Audit',
        ]);

        foreach ($contacts as $typeId => $value) {
            Contact::create([
                'peoples_id' => $people->getId(),
                'contacts_types_id' => $typeId,
                'value' => $value,
                'weight' => 0,
                'is_deleted' => 0,
            ]);
        }

        return Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'title' => $token . ' audit lead',
            'people_id' => $people->getId(),
            'status' => 0,
        ]);
    }

    /**
     * @return list<int>
     */
    private function auditIds(string $missing): array
    {
        $result = new SearchLeadsTool()
            ->withContext(app(Apps::class), auth()->user()->getCurrentCompany(), auth()->user())
            ->__invoke(missing_contact: $missing, status: 'all', limit: 100);

        return array_column($result['leads'], 'lead_id');
    }
}
