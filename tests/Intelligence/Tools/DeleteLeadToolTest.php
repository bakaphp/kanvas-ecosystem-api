<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\DeleteLeadTool;
use Tests\TestCase;

class DeleteLeadToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testSoftDeletesLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $result = new DeleteLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId());

        $this->assertSame('success', $result['status']);

        $isDeleted = DB::connection('crm')->table('leads')->where('id', $lead->getId())->value('is_deleted');
        $this->assertSame(1, (int) $isDeleted);
    }

    /**
     * A soft delete has to stay reversible by restoreLead, so the tool must not drop the lead's
     * custom fields the way the deleteLead GraphQL mutation does.
     */
    public function testKeepsCustomFieldsSoTheDeleteStaysReversible(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $lead->set('budget_range', '50k-100k');

        new DeleteLeadTool()
            ->withContext($app, $company, $user)
            ->__invoke(lead_id: $lead->getId());

        $this->assertSame('50k-100k', $lead->get('budget_range'));
    }

    public function testHallucinatedLeadIdReturnsError(): void
    {
        $user = auth()->user();

        $result = new DeleteLeadTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(lead_id: 999999999);

        $this->assertSame('error', $result['status']);
    }

    /**
     * lead_id is LLM-supplied, so a lead belonging to another company must resolve to nothing
     * rather than being deleted out from under its owner.
     */
    public function testWillNotDeleteAnotherCompanysLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $foreignLead = Lead::factory()->withAppId($app->getId())->create();

        $result = new DeleteLeadTool()
            ->withContext($app, $user->getCurrentCompany(), $user)
            ->__invoke(lead_id: $foreignLead->getId());

        $this->assertSame('error', $result['status']);
        $this->assertFalse((bool) $foreignLead->refresh()->is_deleted);
    }
}
