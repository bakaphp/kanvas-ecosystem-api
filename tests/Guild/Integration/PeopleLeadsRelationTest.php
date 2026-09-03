<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class PeopleLeadsRelationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    private const int OPEN_STATUS = 1;

    public function testLeadsRelationExcludesSoftDeletedLeads(): void
    {
        [$people, $lead] = $this->createPeopleWithOpenLead();

        $this->assertCount(1, $people->leads()->get());

        $lead->softDelete();

        $this->assertCount(
            0,
            $people->leads()->get(),
            'A soft-deleted lead must not surface through the People leads relation',
        );
    }

    public function testHasLeadsFilterExcludesSoftDeletedLeads(): void
    {
        [$people, $lead] = $this->createPeopleWithOpenLead();

        $this->assertTrue(
            $this->matchesOpenLeadFilter($people),
            'A person with an open lead must match the hasLeads filter',
        );

        $lead->softDelete();

        $this->assertFalse(
            $this->matchesOpenLeadFilter($people),
            'A soft-deleted lead must not classify a person as a current customer',
        );
    }

    /**
     * Mirrors the EXISTS that `peoples(hasLeads: ...)` builds for the current-customers list.
     */
    private function matchesOpenLeadFilter(People $people): bool
    {
        return People::query()
            ->where('id', $people->getId())
            ->whereHas(
                'leads',
                fn ($query) => $query->where('leads_status_id', self::OPEN_STATUS)
            )
            ->exists();
    }

    /**
     * @return array{0: People, 1: Lead}
     */
    private function createPeopleWithOpenLead(): array
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
            ->create();

        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => self::OPEN_STATUS]);

        return [$people->fresh(), $lead];
    }
}
