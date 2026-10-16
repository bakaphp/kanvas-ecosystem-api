<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\RecordPeopleNoteAction;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadLeadActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadOrganizationActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ReadPersonActivityTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReadEntityActivityToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testReadsTheLeadActivityNewestFirst(): void
    {
        $lead = $this->seedLead();

        new RecordLeadNoteAction($lead)->execute('Demo completed, lead qualified.', 'note', $this->user());
        new RecordLeadNoteAction($lead)->execute('Sent the pilot proposal.', 'note', $this->user());

        $result = $this->leadTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame($lead->getId(), $result['lead_id']);
        $this->assertSame(2, $result['count']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(
            ['Sent the pilot proposal.', 'Demo completed, lead qualified.'],
            array_column($result['activity'], 'content'),
        );
        $this->assertSame($this->user()->displayname, $result['activity'][0]['author']);
    }

    public function testInternalNotesAreFlagged(): void
    {
        $lead = $this->seedLead();

        new RecordLeadNoteAction($lead)->execute(
            body: 'Sync failed on the ERP push.',
            actingUser: $this->user(),
            isPublic: false,
        );

        $result = $this->leadTool()->__invoke(lead_id: $lead->getId());

        $this->assertTrue($result['activity'][0]['is_internal']);
    }

    public function testPagesBackWithBeforeId(): void
    {
        $lead = $this->seedLead();

        foreach (['first', 'second', 'third'] as $body) {
            new RecordLeadNoteAction($lead)->execute($body, 'note', $this->user());
        }

        $page = $this->leadTool()->__invoke(lead_id: $lead->getId(), limit: 2);

        $this->assertTrue($page['has_more']);
        $this->assertSame(['third', 'second'], array_column($page['activity'], 'content'));

        $next = $this->leadTool()->__invoke(
            lead_id: $lead->getId(),
            limit: 2,
            before_id: $page['activity'][1]['id'],
        );

        $this->assertFalse($next['has_more']);
        $this->assertSame(['first'], array_column($next['activity'], 'content'));
    }

    public function testOnlyTheRequestedLeadsActivityIsReturned(): void
    {
        $lead = $this->seedLead();
        $otherLead = $this->seedLead();

        new RecordLeadNoteAction($otherLead)->execute('Belongs to another lead.', 'note', $this->user());

        $result = $this->leadTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame(0, $result['count']);
    }

    public function testAnotherCompanysLeadIsNotResolved(): void
    {
        $foreignCompany = Companies::factory()->create();
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $foreignCompany->getId())
            ->create();

        new RecordLeadNoteAction($lead)->execute('Foreign note.', 'note', $this->user());

        $result = $this->leadTool()->__invoke(lead_id: $lead->getId());

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('activity', $result);
    }

    public function testReadsAPersonsActivity(): void
    {
        $person = $this->seedPerson();

        new RecordPeopleNoteAction($person)->execute('Prefers WhatsApp after 5pm.', 'note', $this->user());

        $result = new ReadPersonActivityTool()
            ->withContext(app(Apps::class), $this->company(), $this->user())
            ->__invoke(person_id: $person->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame($person->getId(), $result['person_id']);
        $this->assertSame('Prefers WhatsApp after 5pm.', $result['activity'][0]['content']);
    }

    public function testReadsAnOrganizationsActivityById(): void
    {
        $organization = $this->seedOrganization();

        new RecordOrganizationNoteAction($organization)->execute('Renewal due in March.', 'note', $this->user());

        $result = $this->organizationTool()->__invoke(organization_id: $organization->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('Renewal due in March.', $result['activity'][0]['content']);
        $this->assertSame($organization->name, $result['name']);
    }

    public function testReadsAnOrganizationsActivityByName(): void
    {
        $organization = $this->seedOrganization();

        new RecordOrganizationNoteAction($organization)->execute('Found by name.', 'note', $this->user());

        $result = $this->organizationTool()->__invoke(organization_name: $organization->name);

        $this->assertSame($organization->getId(), $result['organization_id']);
        $this->assertSame('Found by name.', $result['activity'][0]['content']);
    }

    public function testACustomerFacingAgentIsRefused(): void
    {
        $lead = $this->seedLead();
        new RecordLeadNoteAction($lead)->execute('Internal pricing floor is 20% off.', 'note', $this->user());

        $type = AgentType::factory()
            ->withAppId(app(Apps::class)->getId())
            ->create(['provider' => 'neuron', 'handler' => SalesAgent::class]);
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['agent_type_id' => $type->getId()]);

        $result = new ReadLeadActivityTool()
            ->withContext(app(Apps::class), $this->company(), $this->user(), $agent)
            ->__invoke(lead_id: $lead->getId());

        $this->assertSame('error', $result['status']);
        $this->assertArrayNotHasKey('activity', $result);
    }

    public function testAToolWiredWithoutTenantContextResolvesNothing(): void
    {
        $person = $this->seedPerson();

        $result = new ReadPersonActivityTool()->__invoke(person_id: $person->getId());

        $this->assertSame('no_tenant_context', $result['reason']);
    }

    private function leadTool(): ReadLeadActivityTool
    {
        return new ReadLeadActivityTool()->withContext(app(Apps::class), $this->company(), $this->user());
    }

    private function organizationTool(): ReadOrganizationActivityTool
    {
        return new ReadOrganizationActivityTool()->withContext(app(Apps::class), $this->company(), $this->user());
    }

    private function user(): Users
    {
        return auth()->user();
    }

    private function company(): Companies
    {
        return $this->user()->getCurrentCompany();
    }

    private function seedLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $this->company()->getId())
            ->create();
    }

    private function seedPerson(): People
    {
        /** @var People $person */
        $person = PeopleFactory::new()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->company()->getId())
            ->withUserId($this->user()->getId())
            ->create();

        return $person;
    }

    private function seedOrganization(): Organization
    {
        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->company()->getId(),
            'users_id' => $this->user()->getId(),
            'name' => 'Activity Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
