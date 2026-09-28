<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Tests\TestCase;

class AddLeadNoteToolTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsLeadNotes;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testWritesTheNoteToTheLeadActivityThread(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new AddLeadNoteTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), note: 'Closed per Max - no response after 4 attempts.');

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString(
            'Closed per Max - no response after 4 attempts.',
            $this->latestLeadNoteContent($lead),
        );
    }

    public function testTheNoteIsAttributedToTheActingAgentUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        new AddLeadNoteTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), note: 'Spoke with the customer.');

        $note = $this->latestLeadNote($lead);

        $this->assertNotNull($note);
        $this->assertSame($user->getId(), (int) $note->users_id);
        $this->assertTrue($note->tags()->where('name', 'agent-note')->exists());
    }

    public function testEmptyNoteIsRejected(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new AddLeadNoteTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId(), note: '   ');

        $this->assertSame('error', $result['status']);
        $this->assertNull($this->latestLeadNote($lead));
    }

    public function testUnknownLeadReturnsErrorInsteadOfThrowing(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $result = new AddLeadNoteTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: 999999999, note: 'anything');

        $this->assertSame('error', $result['status']);
    }
}
