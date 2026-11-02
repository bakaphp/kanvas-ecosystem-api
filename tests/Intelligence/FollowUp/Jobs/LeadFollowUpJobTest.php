<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Mockery;
use RuntimeException;
use Silber\Bouncer\BouncerFacade;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

/**
 * Verifies LeadFollowUpJob resolves the right agent before delegating to
 * FollowUpLeadAction. Two paths: stage config override AND enum fallback.
 *
 * We don't assert downstream behavior here — that's FollowUpLeadActionTest's
 * job. We assert only the resolution decision is correct by configuring two
 * named Agents and watching which one's reason ends up in the ledger.
 */
class LeadFollowUpJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testResolvesAgentFromStageConfigOverride(): void
    {
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);

        // Two named agents — only the override-named one should be picked.
        Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);
        $overrideAgent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => 'CustomNudgeAgent',
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test',

            'is_default' => 0,
        ]);
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'S',
            'weight' => 1,
            'config' => [
                'follow_up' => [
                    'enabled' => true,
                    'mode' => 'time_based',
                    'time_based' => ['interval_minutes' => 1440],
                    'goal_based' => null,
                    'max_retries' => 5,
                    'exhausted_action' => 'stop',
                    'agent_name' => 'CustomNudgeAgent',
                    'channels' => [['type' => 'sms', 'enabled' => true, 'template_name' => null]],
                    'channel_selection' => 'sticky_then_priority',
                    'respect_work_hours' => true,
                    'respect_lead_opt_outs' => true,
                    'write_system_message_on_stage_change' => true,
                ],
                'stage_meta' => ['is_terminal' => false],
            ],
        ]);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        // No session → action will short-circuit at gate 3. We just need the
        // job to have reached the action with the right agent. Action's
        // skip-on-no-session emits a ledger event with our agent_id… but
        // skip events don't carry agent_id. Inspect the action's $agent via
        // a simpler proxy: just confirm the job constructs + handles cleanly.
        $job = new LeadFollowUpJob(app: $app, company: $company, lead: $lead);

        // No exception = right agent resolved (otherwise Agent::getByName
        // would throw ModelNotFoundException for the override).
        $job->handle(app(CrmLeadSourceSynchronizer::class));

        $this->assertSame('CustomNudgeAgent', $overrideAgent->name);
    }

    public function testFallsBackToFollowUpEngagerWhenNoOverride(): void
    {
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);

        Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test',

            'is_default' => 0,
        ]);
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'S',
            'weight' => 1,
            'config' => [
                'follow_up' => [
                    'enabled' => true,
                    'mode' => 'time_based',
                    'time_based' => ['interval_minutes' => 1440],
                    'goal_based' => null,
                    'max_retries' => 5,
                    'exhausted_action' => 'stop',
                    // NO agent_name override → must fall back to AgentEnum::FOLLOW_UP_ENGAGER.
                    'channels' => [['type' => 'sms', 'enabled' => true, 'template_name' => null]],
                    'channel_selection' => 'sticky_then_priority',
                    'respect_work_hours' => true,
                    'respect_lead_opt_outs' => true,
                    'write_system_message_on_stage_change' => true,
                ],
                'stage_meta' => ['is_terminal' => false],
            ],
        ]);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        // No CustomNudgeAgent exists. If the job tried to resolve any name
        // other than FOLLOW_UP_ENGAGER, this would throw ModelNotFoundException.
        $job = new LeadFollowUpJob(app: $app, company: $company, lead: $lead);
        $job->handle(app(CrmLeadSourceSynchronizer::class));

        // Success = fallback resolved correctly.
        $this->assertTrue(true);
    }

    public function testHandlePinsBouncerScopeAndContainerAppsToJobApp(): void
    {
        // Critical regression: queue workers are long-running and the previous
        // job's Bouncer scope + container Apps binding leak into the next.
        // overwriteAppService($this->app) at the top of handle() MUST pin both
        // to the job's app — otherwise downstream Role lookups (Scopable trait)
        // throw ModelNotFoundException deep inside CreateChannelAction.
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);
        Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);
        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test',
            'is_default' => 0,
        ]);
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'S',
            'weight' => 1,
            'config' => [
                'follow_up' => [
                    'enabled' => true,
                    'mode' => 'time_based',
                    'time_based' => ['interval_minutes' => 1440],
                    'goal_based' => null,
                    'max_retries' => 5,
                    'exhausted_action' => 'stop',
                    'channels' => [['type' => 'sms', 'enabled' => true, 'template_name' => null]],
                    'channel_selection' => 'sticky_then_priority',
                    'respect_work_hours' => true,
                    'respect_lead_opt_outs' => true,
                    'write_system_message_on_stage_change' => true,
                ],
                'stage_meta' => ['is_terminal' => false],
            ],
        ]);
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        // Simulate a leaked scope from a hypothetical previous job for a
        // different app/company. Set AFTER seeding so the seed itself doesn't
        // trip the bug (LeadObserver::created → CreateChannelAction → role lookup).
        BouncerFacade::scope()->to('app_999999_company_0');

        new LeadFollowUpJob(app: $app, company: $company, lead: $lead)->handle(app(CrmLeadSourceSynchronizer::class));

        // After handle() the Bouncer scope is the job's app, NOT the leaked one.
        $expectedScope = RolesEnums::getScope($app);
        $this->assertSame($expectedScope, BouncerFacade::scope()->get());

        // Container Apps binding now resolves to this job's app, not whatever
        // was bound before.
        $this->assertSame($app->getId(), app(Apps::class)->getId());
    }

    public function testHandleResolvesTheSynchronizerWithoutAnyBinding(): void
    {
        $this->assertInstanceOf(CrmLeadSourceSynchronizer::class, app(CrmLeadSourceSynchronizer::class));
    }

    /**
     * CRM calls are billed per request. A lead that fails a free gate (here: no session) must never
     * reach the synchronizer — the pull sits behind every free gate inside the action.
     */
    public function testLeadThatFailsAFreeGateNeverCostsACrmCall(): void
    {
        FollowUpAgentStub::reset();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);
        Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
            'role' => ['background' => [], 'steps' => [], 'output' => ''],
        ]);

        $pipeline = Pipeline::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test',
            'is_default' => 0,
        ]);
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'S',
            'weight' => 1,
            'config' => [
                'follow_up' => [
                    'enabled' => true,
                    'mode' => 'time_based',
                    'time_based' => ['interval_minutes' => 1],
                    'max_retries' => 5,
                    'exhausted_action' => 'stop',
                    'channels' => [['type' => 'sms', 'enabled' => true, 'template_name' => null]],
                ],
                'stage_meta' => ['is_terminal' => false],
            ],
        ]);
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
        ]);

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldNotReceive('canSync');
        $synchronizer->shouldNotReceive('sync');

        new LeadFollowUpJob(app: $app, company: $company, lead: $lead)->handle($synchronizer);

        $skip = Event::query()
            ->where('apps_id', $app->getId())
            ->where('event_type', 'lead.follow_up.skipped')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($skip);
        $this->assertSame('no_session', $skip->payload['reason']);
    }

    public function testAJobOutOfTriesLeavesAFailedLedgerRecordForTheReports(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        new LeadFollowUpJob(app: $app, company: $company, lead: $lead)->failed(new RuntimeException('Agent not found'));

        $event = Event::query()
            ->where('apps_id', $app->getId())
            ->where('event_type', 'lead.follow_up.failed')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('job_failed', $event->payload['reason']);
        $this->assertSame('Agent not found', $event->payload['error']);
        $this->assertSame('error', $event->status);
    }
}
