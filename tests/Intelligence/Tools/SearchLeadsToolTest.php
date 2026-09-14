<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
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
            'status' => 2,
        ]);

        $openOnly = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'open', limit: 100);
        $openIds = array_column($openOnly['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $openIds);
        $this->assertNotContains($closedLead->getId(), $openIds);

        $all = new SearchLeadsTool()
            ->withContext($app, $company, $user)
            ->__invoke(query: $token, status: 'all', limit: 100);
        $allIds = array_column($all['leads'], 'lead_id');
        $this->assertContains($openLead->getId(), $allIds);
        $this->assertContains($closedLead->getId(), $allIds);
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
