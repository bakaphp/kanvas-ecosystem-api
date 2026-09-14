<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Tests\TestCase;

/**
 * update_lead could not write the lead row itself — no organization, title, type or source — so an agent
 * asked to associate a lead with an account could only link the *person* to the org (or write a note
 * saying it did) and then report a change the CRM never made.
 */
class UpdateLeadToolRecordFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testLinksTheLeadToAnOrganizationById(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $organization = $this->makeOrganization($company, 'VeSync ' . uniqid());

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), organization_id: $organization->getId());

        $this->assertSame('success', $result['status']);
        $this->assertContains('organization', $result['updated']);
        $this->assertSame($organization->name, $result['organization']);
        $this->assertSame(
            $organization->getId(),
            (int) $lead->fresh()->organization_id,
            'the link has to land on the lead itself, not only in the tool response.',
        );
    }

    public function testResolvesTheOrganizationByName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $name = 'Vesync ' . uniqid();
        $organization = $this->makeOrganization($company, $name);

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), organization_name: $name);

        $this->assertSame('success', $result['status']);
        $this->assertSame($organization->getId(), (int) $lead->fresh()->organization_id);
    }

    public function testAmbiguousOrganizationNameAsksInsteadOfPickingOne(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $shared = 'Vesync' . uniqid();
        $this->makeOrganization($company, $shared . ' North');
        $this->makeOrganization($company, $shared . ' South');

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), organization_name: $shared);

        $this->assertTrue($result['needs_disambiguation']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame(
            0,
            (int) $lead->fresh()->organization_id,
            'an ambiguous name must leave the lead untouched.',
        );
    }

    public function testDoesNotLinkAnOrganizationFromAnotherCompany(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $foreign = $this->makeOrganization(Companies::factory()->create(), 'Foreign ' . uniqid());

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), organization_id: $foreign->getId());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, (int) $lead->fresh()->organization_id);
    }

    public function testUnknownOrganizationNameDoesNotFalselyReportSuccess(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), organization_name: 'Nope ' . uniqid());

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('status', $result);
        $this->assertSame(0, (int) $lead->fresh()->organization_id);
    }

    public function testRenamesTheLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), title: 'Kanvas Presentation & LA Sync');

        $this->assertSame('success', $result['status']);
        $this->assertContains('title', $result['updated']);
        $this->assertSame('Kanvas Presentation & LA Sync', $lead->fresh()->title);
    }

    public function testSetsLeadTypeAndSourceByName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $type = $this->makeLeadType($company, 'Enterprise ' . uniqid());
        $source = $this->makeLeadSource($company, 'Referral ' . uniqid());

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), lead_type: $type->name, source: $source->name);

        $this->assertSame('success', $result['status']);
        $this->assertContains('lead_type', $result['updated']);
        $this->assertContains('source', $result['updated']);

        $lead->refresh();
        $this->assertSame($type->getId(), (int) $lead->leads_types_id);
        $this->assertSame($source->getId(), (int) $lead->leads_sources_id);
    }

    public function testUnknownLeadTypeReturnsTheAvailableOnesInsteadOfInventingIt(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $type = $this->makeLeadType($company, 'Enterprise ' . uniqid());
        $typeBefore = (int) $lead->leads_types_id;

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), lead_type: 'Totally Made Up ' . uniqid());

        $this->assertSame('error', $result['status']);
        $this->assertContains($type->name, $result['available']);
        $this->assertSame($typeBefore, (int) $lead->fresh()->leads_types_id);
    }

    public function testAFailedResolveLeavesEarlierFieldsUnsaved(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $titleBefore = $lead->title;

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(
                lead_id: $lead->getId(),
                title: 'Renamed',
                source: 'Does Not Exist ' . uniqid(),
            );

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            $titleBefore,
            $lead->fresh()->title,
            'a half-resolved call must not leave the lead partially written.',
        );
    }

    public function testDoesNotUseALeadTypeFromAnotherCompany(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $name = 'Foreign Type ' . uniqid();
        $this->makeLeadType(Companies::factory()->create(), $name);
        $typeBefore = (int) $lead->leads_types_id;

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), lead_type: $name);

        $this->assertSame('error', $result['status']);
        $this->assertSame($typeBefore, (int) $lead->fresh()->leads_types_id);
    }

    private function makeLeadType(Companies $company, string $name): LeadType
    {
        return LeadType::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => $name,
            'description' => $name,
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function makeLeadSource(Companies $company, string $name): LeadSource
    {
        return LeadSource::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'name' => $name,
            'description' => $name,
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function makeOrganization(Companies $company, string $name): Organization
    {
        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $name,
            'is_deleted' => 0,
        ]);
    }
}
