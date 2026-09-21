<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\PastOpportunitiesTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class PastOpportunitiesToolTest extends TestCase
{
    public function testLeadRefMarksFirstLeadAsNewCustomerWithAge(): void
    {
        $people = $this->makePeople();
        $people->dob = Carbon::now()->subYears(34)->subDays(10)->format('Y-m-d');
        $people->saveOrFail();

        $lead = $this->makeLead($people);

        $result = $this->withTenant(new LeadRefTool())->__invoke(lead_id: $lead->getId());

        $this->assertSame(34, $result['people']['age']);
        $this->assertSame('new', $result['customer_type']);
        $this->assertSame(0, $result['past_opportunities_count']);
        $this->assertArrayNotHasKey('past_opportunities', $result);
    }

    public function testLeadRefMarksPersonWithPreviousLeadAsReturning(): void
    {
        $people = $this->makePeople();
        $this->makeLead($people);
        $currentLead = $this->makeLead($people);

        $result = $this->withTenant(new LeadRefTool())->__invoke(lead_id: $currentLead->getId());

        $this->assertNull($result['people']['age']);
        $this->assertSame('returning', $result['customer_type']);
        $this->assertSame(1, $result['past_opportunities_count']);
    }

    public function testPastOpportunitiesListsOtherLeadsOfThePerson(): void
    {
        $people = $this->makePeople();
        $previousLead = $this->makeLead($people);
        $currentLead = $this->makeLead($people);

        $result = $this->withTenant(new PastOpportunitiesTool())->__invoke(lead_id: $currentLead->getId());

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $result['past_opportunities']);
        $this->assertSame($previousLead->getId(), $result['past_opportunities'][0]['lead_id']);
        $this->assertArrayHasKey('is_open', $result['past_opportunities'][0]);
    }

    public function testPastOpportunitiesIgnoreDeletedAndOtherCompaniesLeads(): void
    {
        $people = $this->makePeople();
        $currentLead = $this->makeLead($people);

        $deletedLead = $this->makeLead($people);
        $deletedLead->softDelete();

        Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->withPeopleId($people->getId())
            ->create();

        $result = $this->withTenant(new PastOpportunitiesTool())->__invoke(lead_id: $currentLead->getId());

        $this->assertSame([], $result['past_opportunities']);
    }

    private function makePeople(): People
    {
        /** @var Users $user */
        $user = auth()->user();

        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
    }

    private function makeLead(People $people): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($people->companies_id)
            ->withPeopleId($people->getId())
            ->create();
    }

    /**
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function withTenant(object $tool): object
    {
        /** @var Users $user */
        $user = auth()->user();

        return $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }
}
