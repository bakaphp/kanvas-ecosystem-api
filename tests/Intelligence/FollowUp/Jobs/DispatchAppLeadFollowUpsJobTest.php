<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Jobs;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Jobs\DispatchAppLeadFollowUpsJob;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use PHPUnit\Framework\Attributes\Group;
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
    protected array $connectionsToTransact = ['mysql', 'crm', 'social'];

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
                'follow_up' => ['enabled' => true, 'time_based' => ['interval_minutes' => 1440]],
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

        // 4 leads — only the first one should queue. It is older than the stage's default
        // 24h interval so the silence gate lets it through.
        $shouldQueue = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $enabledStage->getId(),
            'created_at' => Carbon::now()->subDays(2),
        ]);
        Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $terminalStage->getId(),
        ]);
        Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $disabledStage->getId(),
        ]);

        // Closed lead (status id outside the open set) in the enabled stage — must NOT queue
        // even though its stage is nudge-enabled and non-terminal.
        $closedLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $enabledStage->getId(),
        ]);
        $closedLead->close();

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

    // Company settings are shared by every paratest process, so the start date must not leak.
    #[Group('serial')]
    public function testLeadsCreatedBeforeTheCompanyFollowUpStartDateAreNotQueued(): void
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
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Enabled',
            'weight' => 1,
            'config' => ['follow_up' => ['enabled' => true, 'time_based' => ['interval_minutes' => 60]]],
        ]);

        $oldLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
            'created_at' => Carbon::now()->subDays(10),
        ]);
        $newLead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create([
            'pipeline_id' => $pipeline->getId(),
            'pipeline_stage_id' => $stage->getId(),
            'created_at' => Carbon::now()->subDay(),
        ]);

        $company->set(
            IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value,
            Carbon::now()->subDays(5)->toDateTimeString(),
        );

        try {
            new DispatchAppLeadFollowUpsJob(app: $app, company: $company)->handle();
        } finally {
            $company->del(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value);
        }

        $newLeadId = $newLead->getId();
        $oldLeadId = $oldLead->getId();
        Queue::assertPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $newLeadId,
        );
        Queue::assertNotPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $oldLeadId,
        );
    }

    public function testLeadFollowedUpInsideTheStageIntervalIsNotQueued(): void
    {
        Queue::fake();

        $stage = $this->createStage(intervalMinutes: 1440);
        $followedUp = $this->createLead($stage);
        $followedUp->bumpFollowUp(['sms'], null);
        $due = $this->createLead($stage);
        $due->set('follow_up_state', [
            'count' => 1,
            'last_at' => Carbon::now()->subDays(2)->toIso8601String(),
        ]);

        $this->dispatchFollowUps();

        $this->assertNotQueued($followedUp);
        $this->assertQueued($due);
    }

    public function testLastMessageInEitherDirectionRestartsTheStageInterval(): void
    {
        Queue::fake();

        $stage = $this->createStage(intervalMinutes: 1440);
        $recentOutbound = $this->createLead($stage);
        $this->addPersonMessage($recentOutbound, fromMe: true, minutesAgo: 30);
        $recentInbound = $this->createLead($stage);
        $this->addPersonMessage($recentInbound, fromMe: false, minutesAgo: 30);
        $silent = $this->createLead($stage);
        $this->addPersonMessage($silent, fromMe: true, minutesAgo: 2 * 1440);

        $this->dispatchFollowUps();

        $this->assertNotQueued($recentOutbound);
        $this->assertNotQueued($recentInbound);
        $this->assertQueued($silent);
    }

    public function testPausedOrExhaustedLeadsAreNotQueued(): void
    {
        Queue::fake();

        $stage = $this->createStage(intervalMinutes: 60);
        $manual = $this->createLead($stage);
        $manual->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);
        $exhausted = $this->createLead($stage);
        $exhausted->markFollowUpExhausted('max_retries');

        $this->dispatchFollowUps();

        $this->assertNotQueued($manual);
        $this->assertNotQueued($exhausted);
    }

    // The action is the only place that exhausts (and may advance) a lead, so a lead at its retry
    // cap reaches it even inside the interval.
    public function testLeadAtMaxRetriesIsQueuedInsideTheIntervalSoTheActionExhaustsIt(): void
    {
        Queue::fake();

        $stage = $this->createStage(intervalMinutes: 1440, maxRetries: 1);
        $atCap = $this->createLead($stage);
        $atCap->bumpFollowUp(['sms'], null);

        $this->dispatchFollowUps();

        $this->assertQueued($atCap);
    }

    private function createStage(int $intervalMinutes, int $maxRetries = 5): PipelineStage
    {
        $user = auth()->user();
        $pipeline = Pipeline::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'system_modules_id' => 0,
            'name' => 'Pipe',
            'is_default' => 0,
        ]);

        return PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Enabled',
            'weight' => 1,
            'config' => [
                'follow_up' => [
                    'enabled' => true,
                    'max_retries' => $maxRetries,
                    'time_based' => ['interval_minutes' => $intervalMinutes],
                ],
            ],
        ]);
    }

    private function createLead(PipelineStage $stage): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create([
                'pipeline_id' => $stage->pipelines_id,
                'pipeline_stage_id' => $stage->getId(),
                'created_at' => Carbon::now()->subDays(10),
            ]);
    }

    private function addPersonMessage(Lead $lead, bool $fromMe, int $minutesAgo): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $lead->companies_id,
                'slug' => 'follow-up-dispatch-' . $lead->getId(),
            ],
            [
                'name' => 'Person channel',
                'description' => 'test',
                'users_id' => $user->getId(),
                'entity_namespace' => People::class,
                'entity_id' => $lead->people_id,
            ]
        );
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'text'],
            ['name' => 'Text']
        );
        $message = Message::create([
            'apps_id' => $app->getId(),
            'companies_id' => $lead->companies_id,
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['content' => 'hi', 'from_me' => $fromMe],
            'created_at' => Carbon::now()->subMinutes($minutesAgo),
            'updated_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);
        $channel->addMessage($message);
    }

    private function dispatchFollowUps(): void
    {
        new DispatchAppLeadFollowUpsJob(
            app: app(Apps::class),
            company: auth()->user()->getCurrentCompany(),
        )->handle();
    }

    private function assertQueued(Lead $lead): void
    {
        $leadId = $lead->getId();
        Queue::assertPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $leadId,
        );
    }

    private function assertNotQueued(Lead $lead): void
    {
        $leadId = $lead->getId();
        Queue::assertNotPushed(
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job): bool => $job->lead->getId() === $leadId,
        );
    }
}
