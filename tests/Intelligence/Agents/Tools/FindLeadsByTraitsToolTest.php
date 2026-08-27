<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsByTraitsTool;
use Kanvas\Intelligence\Agents\Services\EngagementLeadFilterService;
use Kanvas\Intelligence\Agents\Services\FindLeadsByTraitsService;
use Kanvas\Intelligence\Agents\Services\LeadTraits\VariantInterestLeadFilter;
use Kanvas\Intelligence\Agents\Services\VariantInterestSearchService;
use Mockery;
use Tests\TestCase;

class FindLeadsByTraitsToolTest extends TestCase
{
    private function freshLead(Companies $company): Lead
    {
        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create();
        $lead->people->contacts()->whereIn('contacts_types_id', Contact::PHONE_TYPES)->delete();

        return $lead;
    }

    private function tool(Companies $company): FindLeadsByTraitsTool
    {
        return new FindLeadsByTraitsTool()->withContext(app(Apps::class), $company, auth()->user());
    }

    public function testReturnsEligibleRecipientsAndExclusionsWithoutSending(): void
    {
        $company = Companies::factory()->create();

        $eligible = $this->freshLead($company);
        $eligible->people->addPhone('+13055550001');

        $doNotContact = $this->freshLead($company);
        $doNotContact->people->addPhone('+13055550002');
        $doNotContact->set('do_not_contact', 1);

        $this->freshLead($company); // no phone -> no_contact_info

        $result = $this->tool($company)(status: 'all', channel: 'sms');

        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['summary']['candidates_evaluated']);
        $this->assertSame(1, $result['summary']['eligible']);
        $this->assertSame($eligible->getId(), $result['recipients'][0]['lead_id']);
        $this->assertSame(1, $result['summary']['excluded_reasons']['do_not_contact']);
        $this->assertSame(1, $result['summary']['excluded_reasons']['no_contact_info']);
        $this->assertArrayHasKey('note', $result);
    }

    public function testCreatedAfterFilterNarrowsCandidates(): void
    {
        $company = Companies::factory()->create();
        $lead = $this->freshLead($company);
        $lead->people->addPhone('+13055550009');

        $result = $this->tool($company)(
            status: 'all',
            created_after: now()->addDay()->toDateString(),
            channel: 'sms',
        );

        $this->assertSame(0, $result['summary']['candidates_evaluated']);
    }

    public function testInvalidChannelReturnsError(): void
    {
        $result = $this->tool(auth()->user()->getCurrentCompany())(channel: 'smoke-signal');

        $this->assertSame('error', $result['status']);
    }

    public function testVariantCriteriaWithNoInventoryMatchesReturnsNoLeads(): void
    {
        $company = Companies::factory()->create();
        $this->freshLead($company)->people->addPhone('+13055550010');
        $variantSearch = Mockery::mock(VariantInterestSearchService::class);
        $variantSearch->expects('resolve')
            ->with(app(Apps::class), $company, 'RAV4', [])
            ->andReturn([]);
        $finder = new FindLeadsByTraitsService(
            variantFilter: new VariantInterestLeadFilter(search: $variantSearch),
        );
        $tool = new FindLeadsByTraitsTool($finder);
        $tool->withContext(app(Apps::class), $company, auth()->user());

        $result = $tool(status: 'all', variant_query: 'RAV4');

        $this->assertSame(0, $result['summary']['candidates_evaluated']);
        $this->assertSame(0, $result['summary']['matching_inventory_variants']);
        $this->assertArrayNotHasKey('matched_variant_ids', $result['interpreted_criteria']['variant_interest']);
    }

    public function testIncompleteEngagementFiltersToResolvedLeadIds(): void
    {
        $company = Companies::factory()->create();
        $matching = $this->freshLead($company);
        $matching->people->addPhone('+13055550011');
        $this->freshLead($company)->people->addPhone('+13055550012');
        $engagementFilter = Mockery::mock(EngagementLeadFilterService::class);
        $engagementFilter->expects('resolve')
            ->with(app(Apps::class), $company, 'credit-app', 'incomplete')
            ->andReturn([
                'lead_ids' => [$matching->getId()],
                'exclude' => false,
                'matching_engagements' => 1,
                'slugs' => ['credit-app'],
            ]);
        $finder = new FindLeadsByTraitsService(engagementFilter: $engagementFilter);
        $tool = new FindLeadsByTraitsTool($finder);
        $tool->withContext(app(Apps::class), $company, auth()->user());

        $result = $tool(status: 'all', engagement_action: 'credit-app', engagement_completion: 'incomplete');

        $this->assertSame(1, $result['summary']['candidates_evaluated']);
        $this->assertSame($matching->getId(), $result['recipients'][0]['lead_id']);
        $this->assertSame('incomplete', $result['interpreted_criteria']['engagement']['completion']);
        $this->assertStringContainsString('source of truth', $result['engagement_authority']);
    }
}
