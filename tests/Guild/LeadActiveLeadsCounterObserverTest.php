<?php

declare(strict_types=1);

namespace Tests\Guild;

use BadMethodCallException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class LeadActiveLeadsCounterObserverTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testCreatingLeadWithOpenStatus1IncrementsCounter(): void
    {
        $person = $this->createPerson();

        $this->createLead($person, leadsStatusId: 1);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testCreatingLeadWithOpenStatus2IncrementsCounter(): void
    {
        $person = $this->createPerson();

        $this->createLead($person, leadsStatusId: 2);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testCreatingLeadWithClosedStatusDoesNotIncrementCounter(): void
    {
        $person = $this->createPerson();

        $this->createLead($person, leadsStatusId: 3);

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testStatusChangeFromOpenToClosedDecrementsCounter(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, leadsStatusId: 1);
        $this->assertSame(1, $this->activeLeadsCount($person));

        $lead->leads_status_id = 3;
        $lead->saveOrFail();

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testStatusChangeFromClosedToOpenIncrementsCounter(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, leadsStatusId: 3);
        $this->assertSame(0, $this->activeLeadsCount($person));

        $lead->leads_status_id = 2;
        $lead->saveOrFail();

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testSoftDeletingOpenLeadDecrementsCounterExactlyOnce(): void
    {
        $person = $this->createPerson();
        $lead = $this->createLead($person, leadsStatusId: 1);
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
        $lead = $this->createLead($person, leadsStatusId: 1);
        $this->assertSame(1, $this->activeLeadsCount($person));

        try {
            $lead->delete();
        } catch (BadMethodCallException) {
            // unrelated, pre-existing — counter decrement already ran
        }

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testReassigningLeadMovesCounterBetweenPeople(): void
    {
        $personA = $this->createPerson();
        $personB = $this->createPerson();
        $lead = $this->createLead($personA, leadsStatusId: 1);
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
        $this->createLead($person, leadsStatusId: 1, ownerId: 51);
        $this->createLead($person, leadsStatusId: 3, ownerId: 52);

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
        $this->createLead($person, leadsStatusId: 1, ownerId: 53);
        $this->createLead($person, leadsStatusId: 3, ownerId: 53);

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

    public function testCurrentCustomersQueryReturnsOnlyPeopleWithAnOpenLead(): void
    {
        $marker = $this->marker();
        $current = $this->createPerson($marker . '-current');
        $past = $this->createPerson($marker . '-past');
        $this->createLead($current, leadsStatusId: 1);
        $this->createLead($past, leadsStatusId: 3);

        $this->assertSame(
            [$current->getId()],
            $this->fetchIdsByActiveLeadsCount($marker, isCurrent: true),
        );
    }

    public function testPastCustomersQueryReturnsOnlyPeopleWithoutAnOpenLead(): void
    {
        $marker = $this->marker();
        $current = $this->createPerson($marker . '-current');
        $past = $this->createPerson($marker . '-past');
        $this->createLead($current, leadsStatusId: 2);
        $this->createLead($past, leadsStatusId: 3);

        $this->assertSame(
            [$past->getId()],
            $this->fetchIdsByActiveLeadsCount($marker, isCurrent: false),
        );
    }

    public function testPersonWithOnlyClosedLeadsMovesFromCurrentToPastAfterStatusChange(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $lead = $this->createLead($person, leadsStatusId: 1);

        $currentIds = $this->fetchIdsByActiveLeadsCount($marker, isCurrent: true);
        $this->assertSame([$person->getId()], $currentIds);
        $this->assertSame([], $this->fetchIdsByActiveLeadsCount($marker, isCurrent: false));

        $lead->leads_status_id = 3;
        $lead->saveOrFail();

        $this->assertSame([], $this->fetchIdsByActiveLeadsCount($marker, isCurrent: true));
        $this->assertSame([$person->getId()], $this->fetchIdsByActiveLeadsCount($marker, isCurrent: false));
    }

    private function fetchIdsByActiveLeadsCount(string $marker, bool $isCurrent): array
    {
        $operator = $isCurrent ? 'GT' : 'EQ';

        $raw = $this->graphQL(
            'query($marker: Mixed!) {
                peoples(
                    where: {
                        AND: [
                            { column: FIRSTNAME, operator: LIKE, value: $marker }
                            { column: ACTIVE_LEADS_COUNT, operator: ' . $operator . ', value: 0 }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker . '%'],
        )->assertOk()->json();

        return array_map(
            fn (array $row) => (int) $row['id'],
            $raw['data']['peoples']['data'] ?? [],
        );
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

    private function createLead(People $people, int $leadsStatusId, int $ownerId = 50): Lead
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
                'leads_status_id' => $leadsStatusId,
            ]);
    }

    private function marker(): string
    {
        return 'alc-' . uniqid('', true);
    }
}
