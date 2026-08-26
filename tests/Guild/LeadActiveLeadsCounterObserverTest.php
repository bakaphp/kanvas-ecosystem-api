<?php

declare(strict_types=1);

namespace Tests\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Observers\LeadActiveLeadsCounterObserver;
use Tests\TestCase;

final class LeadActiveLeadsCounterObserverTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreatingOpenLeadIncrementsCounter(): void
    {
        $person = $this->createPerson();

        $this->createLead($person, status: 0);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testCreatingClosedLeadDoesNotIncrementCounter(): void
    {
        $person = $this->createPerson();

        $this->createLead($person, status: 5);

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testStatusChangeFromOpenToClosedDecrementsCounter(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, status: 0);
        $this->assertSame(1, $this->activeLeadsCount($person));

        $lead->status = 5;
        $lead->saveOrFail();

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testStatusChangeFromClosedToOpenIncrementsCounter(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, status: 5);
        $this->assertSame(0, $this->activeLeadsCount($person));

        $lead->status = 0;
        $lead->saveOrFail();

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testSoftDeletingOpenLeadDecrementsCounterExactlyOnce(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, status: 0);
        $this->assertSame(1, $this->activeLeadsCount($person));

        $lead->softDelete();

        $this->assertSame(
            0,
            $this->activeLeadsCount($person),
            'softDelete() must decrement exactly once, not double-decrement via both updated() and softDeleted()',
        );
    }

    public function testHardDeletingOpenLeadDecrementsCounter(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, status: 0);
        $this->assertSame(1, $this->activeLeadsCount($person));

        new LeadActiveLeadsCounterObserver()->deleted($lead);

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testReassigningLeadMovesCounterBetweenPeople(): void
    {
        $personA = $this->createPerson();
        $personB = $this->createPerson();
        $lead = $this->createLead($personA, status: 0);
        $this->assertSame(1, $this->activeLeadsCount($personA));
        $this->assertSame(0, $this->activeLeadsCount($personB));

        $lead->people_id = $personB->getId();
        $lead->saveOrFail();

        $this->assertSame(0, $this->activeLeadsCount($personA));
        $this->assertSame(1, $this->activeLeadsCount($personB));
    }

    public function testCrossOwnerCorrectness(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, status: 0, ownerId: 51);
        $this->createLead($person, status: 5, ownerId: 52);

        $this->assertSame(1, $this->activeLeadsCount($person));

        $raw = $this->graphQL(
            'query($marker: Mixed, $ownerId: Mixed!) {
                peoples(
                    where: {
                        AND: [
                            { column: FIRSTNAME, operator: EQ, value: $marker }
                            { column: ACTIVE_LEADS_COUNT, operator: EQ, value: 0 }
                        ]
                    }
                    hasLeads: {
                        AND: [
                            { column: ID, operator: GT, value: "0" }
                            { column: LEADS_OWNER_ID, operator: EQ, value: $ownerId }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker, 'ownerId' => 52],
        )->assertOk()->json();

        $this->assertSame(
            [],
            $raw['data']['peoples']['data'] ?? null,
            'a person with an open lead under a different owner must not appear as "past" for this owner too — being current is global',
        );
    }

    public function testSameOwnerBugScenarioStillResolvesCorrectly(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, status: 0, ownerId: 53);
        $this->createLead($person, status: 5, ownerId: 53);

        $raw = $this->graphQL(
            'query($marker: Mixed) {
                peoples(
                    where: {
                        AND: [
                            { column: FIRSTNAME, operator: EQ, value: $marker }
                            { column: ACTIVE_LEADS_COUNT, operator: GT, value: 0 }
                        ]
                    }
                    hasLeads: {
                        AND: [
                            { column: ID, operator: GT, value: "0" }
                            { column: LEADS_OWNER_ID, operator: EQ, value: 53 }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker],
        )->assertOk()->json();

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            $raw['data']['peoples']['data'] ?? [],
        );

        $this->assertSame([$person->getId()], $ids);
    }

    private function activeLeadsCount(People $person): int
    {
        return (int) People::query()->find($person->getId())->active_leads_count;
    }

    private function createPerson(?string $marker = null): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withContacts()
            ->create(['firstname' => $marker ?? $this->marker()]);

        return $people->fresh();
    }

    private function createLead(People $people, int $status, int $ownerId = 50): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'leads_owner_id' => $ownerId,
                'status' => $status,
            ]);
    }

    private function marker(): string
    {
        return 'alc-' . uniqid('', true);
    }
}
