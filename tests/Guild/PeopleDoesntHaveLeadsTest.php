<?php

declare(strict_types=1);

namespace Tests\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class PeopleDoesntHaveLeadsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testPersonWithOnlyClosedLeadIsIncluded(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 41, statusId: 3);

        $ids = $this->queryDoesntHaveLeads($marker, ownerId: 41);

        $this->assertSame([$person->getId()], $ids);
    }

    public function testPersonWithOnlyActiveLeadIsExcluded(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 42, statusId: 1);

        $ids = $this->queryDoesntHaveLeads($marker, ownerId: 42);

        $this->assertSame([], $ids);
    }

    public function testPersonWithActiveAndClosedLeadUnderSameOwnerIsExcluded(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 43, statusId: 1);
        $this->createLead($person, ownerId: 43, statusId: 3);

        $ids = $this->queryDoesntHaveLeads($marker, ownerId: 43);

        $this->assertSame(
            [],
            $ids,
            'a person with an active lead for this owner must never appear as "past", even if a closed lead also exists',
        );
    }

    public function testPersonWithNoLeadForThisOwnerIsExcluded(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 99, statusId: 3);

        $ids = $this->queryDoesntHaveLeads($marker, ownerId: 44);

        $this->assertSame(
            [],
            $ids,
            'a person who was never a lead for this owner must not appear as "past" for it',
        );
    }

    public function testConditionOrderDoesNotAffectResult(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 45, statusId: 1);
        $this->createLead($person, ownerId: 45, statusId: 3);

        $raw = $this->graphQL(
            'query($marker: Mixed, $ownerId: Mixed!) {
                peoples(
                    where: { column: FIRSTNAME, operator: EQ, value: $marker }
                    doesntHaveLeads: {
                        AND: [
                            { column: LEADS_STATUS_ID, operator: IN, value: [1, 2] }
                            { column: LEADS_OWNER_ID, operator: EQ, value: $ownerId }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker, 'ownerId' => 45],
        )->assertOk()->json();

        $this->assertSame([], $raw['data']['peoples']['data'] ?? null);
    }

    public function testHasLeadsForCurrentIsUnaffected(): void
    {
        $marker = $this->marker();
        $person = $this->createPerson($marker);
        $this->createLead($person, ownerId: 46, statusId: 1);
        $this->createLead($person, ownerId: 46, statusId: 3);

        $raw = $this->graphQL(
            'query($marker: Mixed, $ownerId: Mixed!) {
                peoples(
                    where: { column: FIRSTNAME, operator: EQ, value: $marker }
                    hasLeads: {
                        AND: [
                            { column: LEADS_OWNER_ID, operator: EQ, value: $ownerId }
                            { column: LEADS_STATUS_ID, operator: IN, value: [1, 2] }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker, 'ownerId' => 46],
        )->assertOk()->json();

        $ids = array_map(
            fn (array $row) => (int) $row['id'],
            $raw['data']['peoples']['data'] ?? [],
        );

        $this->assertSame([$person->getId()], $ids);
    }

    /**
     * @return array<int, int>
     */
    private function queryDoesntHaveLeads(string $marker, int $ownerId): array
    {
        $raw = $this->graphQL(
            'query($marker: Mixed, $ownerId: Mixed!) {
                peoples(
                    where: { column: FIRSTNAME, operator: EQ, value: $marker }
                    doesntHaveLeads: {
                        AND: [
                            { column: LEADS_OWNER_ID, operator: EQ, value: $ownerId }
                            { column: LEADS_STATUS_ID, operator: IN, value: [1, 2] }
                        ]
                    }
                ) {
                    data { id }
                }
            }',
            ['marker' => $marker, 'ownerId' => $ownerId],
        )->assertOk()->json();

        return array_map(
            fn (array $row) => (int) $row['id'],
            $raw['data']['peoples']['data'] ?? [],
        );
    }

    private function createPerson(string $marker): People
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
            ->create(['firstname' => $marker]);

        return $people->fresh();
    }

    private function createLead(People $people, int $ownerId, int $statusId): Lead
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
                'leads_status_id' => $statusId,
            ]);
    }

    private function marker(): string
    {
        return 'dhl-' . uniqid('', true);
    }
}
