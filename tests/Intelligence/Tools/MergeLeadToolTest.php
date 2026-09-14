<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MergeLeadTool;
use Tests\TestCase;

class MergeLeadToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'action_engine'];

    public function testMovesChildRowsAndSoftDeletesTheSource(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $source = $this->makeLead();
        $target = $this->makeLead();

        DB::connection('crm')->table('deals')->insert([
            'uuid' => (string) fake()->uuid(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $user->getCurrentBranch()->getId(),
            'users_id' => $user->getId(),
            'owner_id' => $user->getId(),
            'people_id' => $source->people_id,
            'leads_id' => $source->getId(),
            'title' => 'Deal for merge ' . uniqid(),
            'created_at' => now(),
            'is_deleted' => 0,
        ]);

        LeadParticipant::create([
            'leads_id' => $source->getId(),
            'peoples_id' => $source->people_id,
            'participants_types_id' => 1,
            'created_at' => now(),
        ]);

        $result = $this->tool()->__invoke(
            source_lead_id: $source->getId(),
            target_lead_id: $target->getId(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($target->getId(), $result['lead_id']);

        $this->assertSame(
            1,
            DB::connection('crm')->table('deals')->where('leads_id', $target->getId())->count()
        );
        $this->assertTrue(
            LeadParticipant::where('leads_id', $target->getId())
                ->where('peoples_id', $source->people_id)
                ->exists()
        );

        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted);
        $this->assertSame($target->getId(), (int) $source->merged_into_leads_id);
    }

    /**
     * leads_participants is unique on (leads_id, peoples_id), so a person on BOTH leads would blow
     * up a blind UPDATE — the source's row has to be dropped instead.
     */
    public function testDropsTheSourceRowWhenTheTargetAlreadyHasTheSameParticipant(): void
    {
        $source = $this->makeLead();
        $target = $this->makeLead();
        $peopleId = $source->people_id;

        foreach ([$source, $target] as $lead) {
            LeadParticipant::create([
                'leads_id' => $lead->getId(),
                'peoples_id' => $peopleId,
                'participants_types_id' => 1,
                'created_at' => now(),
            ]);
        }

        $result = $this->tool()->__invoke(
            source_lead_id: $source->getId(),
            target_lead_id: $target->getId(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, LeadParticipant::where('leads_id', $source->getId())->count());
        $this->assertSame(1, LeadParticipant::where('leads_id', $target->getId())->count());
    }

    public function testFillsContactDetailTheTargetIsMissing(): void
    {
        $source = $this->makeLead(['phone' => '8095551234', 'email' => 'source@example.com']);
        $target = $this->makeLead(['phone' => '', 'email' => 'target@example.com']);

        $this->tool()->__invoke(
            source_lead_id: $source->getId(),
            target_lead_id: $target->getId(),
        );

        $target->refresh();
        $this->assertSame('8095551234', $target->phone);
        $this->assertSame('target@example.com', $target->email);
    }

    public function testMovesCustomFieldsTheTargetDoesNotAlreadyHave(): void
    {
        $source = $this->makeLead();
        $target = $this->makeLead();

        $source->set('trade_in_vin', 'VIN-SOURCE');
        $source->set('budget_range', '50k-100k');
        $target->set('budget_range', '10k-20k');

        $this->tool()->__invoke(
            source_lead_id: $source->getId(),
            target_lead_id: $target->getId(),
        );

        $this->assertSame('VIN-SOURCE', $target->get('trade_in_vin'));
        $this->assertSame('10k-20k', $target->get('budget_range'));
    }

    public function testRefusesToMergeALeadIntoItself(): void
    {
        $lead = $this->makeLead();

        $result = $this->tool()->__invoke(
            source_lead_id: $lead->getId(),
            target_lead_id: $lead->getId(),
        );

        $this->assertSame('error', $result['status']);
        $this->assertFalse((bool) $lead->refresh()->is_deleted);
    }

    public function testUnknownLeadIdReturnsError(): void
    {
        $result = $this->tool()->__invoke(
            source_lead_id: 999999999,
            target_lead_id: $this->makeLead()->getId(),
        );

        $this->assertSame('error', $result['status']);
    }

    /**
     * Both ids are LLM-supplied, so a lead from another company must not be reachable — merging one
     * in would hand its whole history to this tenant.
     */
    public function testWillNotMergeAnotherCompanysLead(): void
    {
        $app = app(Apps::class);
        $target = $this->makeLead();
        $foreignLead = Lead::factory()->withAppId($app->getId())->create();

        $result = $this->tool()->__invoke(
            source_lead_id: $foreignLead->getId(),
            target_lead_id: $target->getId(),
        );

        $this->assertSame('error', $result['status']);
        $this->assertFalse((bool) $foreignLead->refresh()->is_deleted);
    }

    private function tool(): MergeLeadTool
    {
        $user = auth()->user();

        return new MergeLeadTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeLead(array $attributes = []): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), $user->getCurrentCompany()->getId())
            ->create($attributes);
    }
}
