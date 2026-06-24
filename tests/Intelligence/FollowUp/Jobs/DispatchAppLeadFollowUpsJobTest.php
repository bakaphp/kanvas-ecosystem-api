<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppLeadFollowUpsJob;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Tests\TestCase;

/**
 * Verifies the candidate-lead SQL filter — only leads in nudge-enabled,
 * non-terminal stages get a LeadFollowUpJob queued. We don't run the inner
 * jobs (Queue::fake()), just inspect what got dispatched.
 */
class DispatchAppLeadFollowUpsJobTest extends TestCase
{
    use DatabaseTransactions;

    // Leads live on `crm` and the candidate query is sensitive to lead rows
    // from prior tests — wrap both connections so rollback is complete.
    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testOnlyEnabledNonTerminalActiveLeadsAreQueued(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Pipe',

            'is_default' => 0,
        ]);

        $enabledStage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Enabled',
            'weight' => 1,
            'config' => [
                'follow_up' => ['enabled' => true],
                'stage_meta' => ['is_terminal' => false],
            ],
        ]);

        $terminalStage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Terminal',
            'weight' => 2,
            'config' => [
                'follow_up' => ['enabled' => true],
                'stage_meta' => ['is_terminal' => true],
            ],
        ]);

        $disabledStage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Disabled',
            'weight' => 3,
            'config' => null,
        ]);

        // 4 leads — only the first one should queue.
        $shouldQueue = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $enabledStage->getId(),
        ]);
        Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $terminalStage->getId(),
        ]);
        Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $disabledStage->getId(),
        ]);

        // Closed lead (status >= 2) in the enabled stage — must NOT queue even
        // though its stage is nudge-enabled and non-terminal.
        $closedLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $enabledStage->getId(),
            'status' => 2,
        ]);

        new DispatchAppLeadFollowUpsJob(app: $app, company: $company)->handle();

        // Assert the specific eligible lead was dispatched, and that the
        // disqualified stages' leads (terminal + disabled-config) were NOT.
        // Avoid an absolute-count assertion — other tests in the suite may
        // leave leads in follow-up-enabled stages on the same tenant.
        Queue::assertPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $shouldQueue->getId(),
        );

        $terminalStageId = $terminalStage->getId();
        $disabledStageId = $disabledStage->getId();

        Queue::assertNotPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->pipeline_stage_id === $terminalStageId,
        );

        Queue::assertNotPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->pipeline_stage_id === $disabledStageId,
        );

        $closedLeadId = $closedLead->getId();
        Queue::assertNotPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $closedLeadId,
        );
    }
}
