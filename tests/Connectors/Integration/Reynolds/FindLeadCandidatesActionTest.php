<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Actions\FindLeadCandidatesAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class FindLeadCandidatesActionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * ecosystem is not optional here — $lead->set() writes apps_custom_fields on
     * that connection, so without it the CLIENT_ID cases leak into each other.
     */
    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testReturnsEmptyWhenGivenNoIdentifiers(): void
    {
        $this->assertSame([], $this->action()->execute());
    }

    public function testMatchesByPhone(): void
    {
        $lead = $this->createLead();
        $phone = Str::sanitizePhoneNumber($lead->people->getCellPhones()->first()->value);

        $results = $this->action()->execute(phone: $phone);

        $this->assertContains($lead->getId(), array_column($results, 'id'));
    }

    public function testMatchesByEmail(): void
    {
        $lead = $this->createLead();
        $email = $lead->people->getEmails()->first()->value;

        $results = $this->action()->execute(email: $email);

        $this->assertContains($lead->getId(), array_column($results, 'id'));
    }

    public function testResultsCarryTheSharedShapeIncludingANumericRank(): void
    {
        $lead = $this->createLead();
        $email = $lead->people->getEmails()->first()->value;

        $results = $this->action()->execute(email: $email);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('rank', $results[0]);
        $this->assertIsFloat($results[0]['rank']);
        $this->assertArrayHasKey('people_id', $results[0]);
        $this->assertArrayHasKey('custom_fields', $results[0]);
    }

    public function testClientIdAnchorRanksOneAndSortsAheadOfAContactOnlyMatch(): void
    {
        $anchored = $this->createLead();
        $anchored->set(CustomFieldEnum::CLIENT_ID->value, '4369283');

        $contactOnly = $this->createLead();
        $email = $contactOnly->people->getEmails()->first()->value;

        $results = $this->action()->execute(clientId: '4369283', email: $email);

        $this->assertSame($anchored->getId(), $results[0]['id']);
        $this->assertSame(1.0, $results[0]['rank']);
        $this->assertContains($contactOnly->getId(), array_column($results, 'id'));
    }

    public function testExcludesLeadsInATerminalStatus(): void
    {
        $lead = $this->createLead(statusName: 'Sold');
        $email = $lead->people->getEmails()->first()->value;

        $results = $this->action()->execute(email: $email);

        $this->assertNotContains($lead->getId(), array_column($results, 'id'));
    }

    /**
     * Reynolds dealers publish prospects as "Open", which the active-status
     * whitelist rejects. Excluding terminal statuses instead is the whole reason
     * this action does not reuse getPeopleActiveLeads().
     */
    public function testIncludesAnOpenStatusTheActiveWhitelistWouldReject(): void
    {
        $lead = $this->createLead(statusName: 'Open');
        $email = $lead->people->getEmails()->first()->value;

        $results = $this->action()->execute(email: $email);

        $this->assertContains($lead->getId(), array_column($results, 'id'));
    }

    public function testCompanyMappingOverridesWhichStatusesCountAsTerminal(): void
    {
        $deadLead = $this->createLead(statusName: 'Dead');
        $email = $deadLead->people->getEmails()->first()->value;
        $soldLead = $this->createLead(statusName: 'Sold', people: $deadLead->people);

        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::MAPPING_STATUS_CRM->value, ['closed' => ['dead']]);

        try {
            $ids = array_column($this->action()->execute(email: $email), 'id');

            $this->assertNotContains($deadLead->getId(), $ids, '"dead" is terminal under the override');
            $this->assertContains($soldLead->getId(), $ids, '"sold" is not terminal under the override');
        } finally {
            $company->del(ConfigurationEnum::MAPPING_STATUS_CRM->value);
        }
    }

    public function testReturnsEveryLeadSharingAContactSortedByRank(): void
    {
        $people = $this->createPeople();
        $first = $this->createLead(people: $people);
        $second = $this->createLead(people: $people);

        $results = $this->action()->execute(email: $people->getEmails()->first()->value);

        $ids = array_column($results, 'id');
        $this->assertContains($first->getId(), $ids);
        $this->assertContains($second->getId(), $ids);

        $ranks = array_column($results, 'rank');
        $sorted = $ranks;
        rsort($sorted);
        $this->assertSame($sorted, $ranks, 'candidates must come back best-match first');
    }

    /**
     * The read-only pin. This action runs from an interactive picker and may be
     * re-run freely, so it must never mutate. eLead's equivalent closes active
     * leads during its fallback; if anyone ports that here, this fails.
     */
    public function testIsReadOnlyAndMutatesNothing(): void
    {
        $lead = $this->createLead();
        $email = $lead->people->getEmails()->first()->value;
        $statusBefore = $lead->leads_status_id;

        $this->action()->execute(clientId: '999999', email: $email);

        $this->assertSame($statusBefore, $lead->refresh()->leads_status_id);
        $this->assertNull(
            $lead->get(CustomFieldEnum::CLIENT_ID->value),
            'the action must not stamp the inbound CRM id onto whatever ranked first'
        );
    }

    private function action(): FindLeadCandidatesAction
    {
        return new FindLeadCandidatesAction(
            app(Apps::class),
            auth()->user()->getCurrentCompany()
        );
    }

    private function createPeople(): People
    {
        $user = auth()->user();

        return People::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();
    }

    private function createLead(string $statusName = 'Open', ?People $people = null): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $people ??= $this->createPeople();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $this->statusId($statusName, $app, $company)]);
    }

    private function statusId(string $name, Apps $app, Companies $company): int
    {
        return (int) DB::connection('crm')->table('leads_status')->insertGetId([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $name,
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
