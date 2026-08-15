<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Tests\TestCase;

/**
 * update_lead used to write `notes` and `disposition` to custom fields nothing reads, so the tool
 * reported success on changes that never surfaced anywhere. Both must land on the activity thread.
 */
class UpdateLeadToolNotesTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsLeadNotes;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testNotesLandOnTheActivityThreadNotAWriteOnlyCustomField(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), notes: 'Prospect asked for a callback next Tuesday.');

        $this->assertSame('success', $result['status']);
        $this->assertContains('notes', $result['updated']);

        $this->assertStringContainsString(
            'Prospect asked for a callback next Tuesday.',
            $this->latestLeadNoteContent($lead),
            'update_lead notes must be recorded as a visible note.',
        );
    }

    public function testDispositionIsMirroredToTheActivityThread(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), disposition: 'unqualified');

        $this->assertSame('success', $result['status']);

        $this->assertStringContainsString('unqualified', $this->latestLeadNoteContent($lead));
    }

    public function testDispositionDoesNotTouchTheLeadStatus(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $statusBefore = (int) $lead->leads_status_id;

        new UpdateLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), disposition: 'unqualified');

        $lead->refresh();
        $this->assertSame(
            $statusBefore,
            (int) $lead->leads_status_id,
            'disposition is a qualification label — closing a lead is set_lead_status\' job.',
        );
    }
}
