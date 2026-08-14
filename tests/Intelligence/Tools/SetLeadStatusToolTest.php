<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Tests\TestCase;

class SetLeadStatusToolTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsLeadNotes;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testSetsTheLeadStatusColumnTheUiReads(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $open = $this->makeStatus($app->getId(), $company->getId(), 'Open Test');
        $closed = $this->makeStatus($app->getId(), $company->getId(), 'Closed Test');

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'leads_status_id' => $open->getId(),
        ]);

        $result = new SetLeadStatusTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), status: 'closed test', reason: 'Cold - no response');

        $this->assertSame('success', $result['status']);
        $this->assertSame('Open Test', $result['previous_status']);
        $this->assertSame('Closed Test', $result['new_status']);

        $lead->refresh();
        $this->assertSame($closed->getId(), (int) $lead->leads_status_id);
        $this->assertSame('Cold - no response', $lead->reason_lost);
    }

    public function testRecordsTheChangeAsAVisibleNoteOnTheLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->makeStatus($app->getId(), $company->getId(), 'Lost Test');

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new SetLeadStatusTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), status: 'Lost Test', reason: 'Bought elsewhere');

        $this->assertTrue($result['note_recorded']);

        $content = $this->latestLeadNoteContent($lead);
        $this->assertStringContainsString('Lost Test', $content);
        $this->assertStringContainsString('Bought elsewhere', $content);
    }

    public function testUnknownStatusReturnsTheValidOptionsAndLeavesTheLeadUntouched(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $current = $this->makeStatus($app->getId(), $company->getId(), 'Working Test');

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'leads_status_id' => $current->getId(),
        ]);

        $result = new SetLeadStatusTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), status: 'cold');

        $this->assertSame('error', $result['status']);
        $this->assertContains('Working Test', $result['available_statuses']);

        $lead->refresh();
        $this->assertSame($current->getId(), (int) $lead->leads_status_id);
    }

    public function testLeadFromAnotherCompanyIsNotResolvable(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->makeStatus($app->getId(), $company->getId(), 'Closed Foreign Test');

        $foreignLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        // Move it out of the acting tenant after the factory has built its branch/pipeline graph.
        Lead::query()
            ->where('id', $foreignLead->getId())
            ->update(['companies_id' => $company->getId() + 99999]);
        $foreignLead->refresh();

        $result = new SetLeadStatusTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $foreignLead->getId(), status: 'Closed Foreign Test');

        $this->assertSame('error', $result['status']);

        $foreignLead->refresh();
        $this->assertNotSame(
            'Closed Foreign Test',
            $foreignLead->status()->first()?->name,
        );
    }

    private function makeStatus(int $appId, int $companyId, string $name): LeadStatus
    {
        return LeadStatus::create([
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'name' => $name,
            'is_default' => 0,
        ]);
    }
}
