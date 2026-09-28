<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Tests\TestCase;

/**
 * advance_stage only walks one step forward, so an agent told "move this to Negotiation" (or asked to
 * put a lead back) either could not comply or spammed advance_stage until the run budget tripped.
 */
class UpdateLeadToolPipelineStageTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testMovesTheLeadToANamedStage(): void
    {
        [$lead, $stages] = $this->makeLeadOnPipeline();

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), pipeline_stage: 'Negotiation');

        $this->assertSame('success', $result['status']);
        $this->assertContains('pipeline_stage', $result['updated']);
        $this->assertTrue($result['stage_advanced']);
        $this->assertSame('Negotiation', $result['current_pipeline_stage']);
        $this->assertSame($stages['Negotiation']->getId(), (int) $lead->fresh()->pipeline_stage_id);
    }

    public function testMovesTheLeadBackwards(): void
    {
        [$lead, $stages] = $this->makeLeadOnPipeline('Negotiation');

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), pipeline_stage: 'New');

        $this->assertSame('success', $result['status']);
        $this->assertSame($stages['New']->getId(), (int) $lead->fresh()->pipeline_stage_id);
    }

    public function testUnknownStageReturnsThePipelinesStages(): void
    {
        [$lead, $stages] = $this->makeLeadOnPipeline();
        $stageBefore = (int) $lead->pipeline_stage_id;

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), pipeline_stage: 'Blast Off');

        $this->assertSame('error', $result['status']);
        $this->assertSame(array_keys($stages), $result['available']);
        $this->assertSame($stageBefore, (int) $lead->fresh()->pipeline_stage_id);
    }

    public function testStageNameAndAdvanceStageTogetherIsRejected(): void
    {
        [$lead] = $this->makeLeadOnPipeline();
        $stageBefore = (int) $lead->pipeline_stage_id;

        $result = $this->tool()->__invoke(
            lead_id: $lead->getId(),
            pipeline_stage: 'Negotiation',
            advance_stage: true,
        );

        $this->assertSame('error', $result['status']);
        $this->assertSame($stageBefore, (int) $lead->fresh()->pipeline_stage_id);
    }

    public function testAdvanceStageStillWorks(): void
    {
        [$lead, $stages] = $this->makeLeadOnPipeline();

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), advance_stage: true);

        $this->assertTrue($result['stage_advanced']);
        $this->assertSame($stages['Qualifying']->getId(), (int) $lead->fresh()->pipeline_stage_id);
    }

    public function testALeadWithNoPipelineSaysSoInsteadOfCrashing(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        // The observer assigns the company's default pipeline on create, so clear it behind its back.
        Lead::query()->where('id', $lead->getId())->update(['pipeline_id' => 0, 'pipeline_stage_id' => 0]);

        $result = $this->tool()->__invoke(lead_id: $lead->getId(), pipeline_stage: 'Negotiation');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not on a pipeline', $result['message']);
    }

    private function tool(): UpdateLeadTool
    {
        $user = auth()->user();

        return new UpdateLeadTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    /**
     * @return array{0: Lead, 1: array<string, PipelineStage>}
     */
    private function makeLeadOnPipeline(string $startOn = 'New'): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Sales ' . uniqid(),
            'is_default' => 0,
        ]);

        $stages = [];
        foreach (['New', 'Qualifying', 'Negotiation'] as $weight => $name) {
            $stages[$name] = PipelineStage::create([
                'pipelines_id' => $pipeline->getId(),
                'name' => $name,
                'weight' => $weight + 1,
                'config' => null,
            ]);
        }

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stages[$startOn]->getId(),
        ]);

        return [$lead, $stages];
    }
}
