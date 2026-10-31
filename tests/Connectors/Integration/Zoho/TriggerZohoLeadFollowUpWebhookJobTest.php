<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Jobs\TriggerZohoLeadFollowUpWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\FollowUp\Jobs\LeadFollowUpJob;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\TestCase;

class TriggerZohoLeadFollowUpWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'workflow'];

    private Apps $testApp;
    private Companies $company;
    private Users $user;
    private Pipeline $pipeline;
    private PipelineStage $firstStage;
    private PipelineStage $secondStage;
    private Lead $lead;
    private string $zohoLeadId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->testApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = Companies::getById($this->user->getCurrentCompany()->getId());
        $this->zohoLeadId = (string) fake()->unique()->numerify('5725############');

        $this->pipeline = $this->createPipeline($this->testApp->getId());
        $this->firstStage = $this->createStage('first_step', 'first_template', 1);
        $this->secondStage = $this->createStage('second_step', 'second_template', 2);
        $this->lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'pipeline_id' => $this->pipeline->getId(),
                'pipeline_stage_id' => $this->firstStage->getId(),
            ]);
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, $this->zohoLeadId);
    }

    public function testQueuesAForcedFollowUpAndMovesTheLeadToTheStep(): void
    {
        $result = $this->runWebhook([]);

        $this->assertSame('Lead follow-up queued', $result['message']);
        $this->assertSame($this->secondStage->getId(), $this->lead->fresh()->pipeline_stage_id);
        $this->assertFollowUpQueued();
    }

    public function testLeadAlreadyOnTheStepIsQueuedWithoutMoving(): void
    {
        $this->lead->pipeline_stage_id = $this->secondStage->getId();
        $this->lead->saveQuietly();
        $this->lead->bumpFollowUp(['sms'], 'second_template');

        $this->runWebhook([]);

        $this->assertSame(1, $this->lead->fresh()->getFollowUpStateCount());
        $this->assertFollowUpQueued();
    }

    public function testRejectsAnUnknownLead(): void
    {
        $this->assertStopped('lead_not_found', $this->runWebhook(['zoho_lead_id' => 'missing']));
    }

    public function testDoesNotFindALeadFromAnotherCompany(): void
    {
        $this->lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, null);

        $otherCompany = Companies::factory()->create();
        $otherLead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $otherCompany->getId())
            ->create();
        $otherLead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, $this->zohoLeadId);

        $this->assertStopped('lead_not_found', $this->runWebhook([]));
    }

    public function testRejectsAnUnknownCampaign(): void
    {
        $this->assertStopped('campaign_not_found', $this->runWebhook(['campaign_key' => 'missing']));
    }

    public function testDoesNotUseACampaignFromAnotherApp(): void
    {
        $otherAppPipeline = $this->createPipeline($this->testApp->getId() + 1);

        $this->assertStopped('campaign_not_found', $this->runWebhook(['campaign_key' => $otherAppPipeline->slug]));
    }

    public function testAFastRetryDoesNotQueueASecondFollowUp(): void
    {
        $this->runWebhook([]);
        $retry = $this->runWebhook([]);

        $this->assertSame('already_queued', $retry['reason']);
        Queue::assertPushed(LeadFollowUpJob::class, 1);
    }

    public function testRejectsAnUnknownStep(): void
    {
        $this->assertStopped('step_not_found', $this->runWebhook(['step_key' => 'missing']));
    }

    public function testRejectsATemplateTheStageDoesNotUse(): void
    {
        $this->assertStopped('template_mismatch', $this->runWebhook(['template_key' => 'first_template']));
    }

    public function testNeverMovesALeadBetweenPipelines(): void
    {
        $other = $this->createPipeline($this->testApp->getId());
        $otherStage = PipelineStage::create([
            'pipelines_id' => $other->getId(),
            'name' => 'Elsewhere',
            'weight' => 1,
        ]);
        $this->lead->pipeline_stage_id = $otherStage->getId();
        $this->lead->saveQuietly();

        $this->assertStopped('lead_on_another_pipeline', $this->runWebhook([]));
        $this->assertSame($otherStage->getId(), $this->lead->fresh()->pipeline_stage_id);
    }

    public function testNeverMovesALeadBackToAnEarlierStage(): void
    {
        $this->lead->pipeline_stage_id = $this->secondStage->getId();
        $this->lead->saveQuietly();

        $result = $this->runWebhook([
            'step_key' => 'first_step',
            'template_key' => 'first_template',
        ]);

        $this->assertStopped('step_behind_current_stage', $result);
        $this->assertSame($this->secondStage->getId(), $this->lead->fresh()->pipeline_stage_id);
    }

    public function testACustomerReplyStopsTheStepAndMarksTheLead(): void
    {
        $this->lead->bumpFollowUp(['sms'], 'first_template');
        $this->addInboundMessage(Carbon::now()->addMinute());

        $this->assertStopped(TriggerZohoLeadFollowUpWebhookJob::CUSTOMER_REPLIED, $this->runWebhook([]));

        $lead = $this->lead->fresh();
        $this->assertSame(TriggerZohoLeadFollowUpWebhookJob::CUSTOMER_REPLIED, $lead->getFollowUpExhaustedReason());
        $this->assertSame($this->firstStage->getId(), $lead->pipeline_stage_id);
    }

    public function testLaterStepsDoNothingOnceTheCustomerReplied(): void
    {
        $this->lead->markFollowUpExhausted(TriggerZohoLeadFollowUpWebhookJob::CUSTOMER_REPLIED);

        $this->assertStopped(TriggerZohoLeadFollowUpWebhookJob::CUSTOMER_REPLIED, $this->runWebhook([]));
        $this->assertSame($this->firstStage->getId(), $this->lead->fresh()->pipeline_stage_id);
    }

    public function testAMessageBeforeOurLastTouchIsNotAReply(): void
    {
        $this->addInboundMessage(Carbon::now()->subDay());
        $this->lead->bumpFollowUp(['sms'], 'first_template');

        $this->runWebhook([]);

        $this->assertFollowUpQueued();
    }

    public function testAReplyBeforeOurFirstTouchStopsTheStep(): void
    {
        $this->assertNull($this->lead->getFollowUpLastAt());
        $this->assertNull($this->lead->getFollowUpStageEnteredAt());
        $this->addInboundMessage(Carbon::now()->addMinute());

        $this->assertStopped(TriggerZohoLeadFollowUpWebhookJob::CUSTOMER_REPLIED, $this->runWebhook([]));
        $this->assertSame($this->firstStage->getId(), $this->lead->fresh()->pipeline_stage_id);
    }

    public function testAMessageBeforeTheLeadExistedIsNotAReply(): void
    {
        $this->addInboundMessage(Carbon::now()->subDay());

        $this->runWebhook([]);

        $this->assertFollowUpQueued();
    }

    public function testAuthenticatesOnlyWithTheConfiguredSecret(): void
    {
        $receiver = $this->receiver(['shared_secret' => 'right']);
        $unset = $this->receiver([]);
        $authenticate = fn (ReceiverWebhook $webhook, array $headers, array $body = []) => TriggerZohoLeadFollowUpWebhookJob::authenticateRequest(
            $this->request($headers, $body),
            $webhook
        );

        $this->assertTrue($authenticate($receiver, ['X-Webhook-Secret' => 'right']));
        $this->assertTrue($authenticate($receiver, [], ['secret' => 'right']));
        $this->assertFalse($authenticate($receiver, ['X-Webhook-Secret' => 'wrong']));
        $this->assertFalse($authenticate($receiver, []));
        $this->assertFalse($authenticate($unset, []));
        $this->assertFalse($authenticate($receiver, [], ['secret' => ['right']]));
    }

    private function runWebhook(array $overrides): array
    {
        $receiver = $this->receiver(['shared_secret' => 'right']);

        $call = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => 'https://graphapi.kanvas.dev/v1/receiver/' . $receiver->uuid,
            'headers' => [],
            'payload' => [
                'zoho_lead_id' => $this->zohoLeadId,
                'campaign_key' => $this->pipeline->slug,
                'step_key' => 'second_step',
                'template_key' => 'second_template',
                ...$overrides,
            ],
            'raw_payload' => '{}',
            'status' => 'pending',
            'results' => [],
        ]);

        $result = new TriggerZohoLeadFollowUpWebhookJob($call)->handle();

        $this->assertSame('success', $call->fresh()->status, json_encode($call->fresh()->exception));

        return $result;
    }

    private function assertStopped(string $reason, array $result): void
    {
        $this->assertSame($reason, $result['reason']);
        Queue::assertNotPushed(LeadFollowUpJob::class);
    }

    private function assertFollowUpQueued(): void
    {
        Queue::assertPushedOn(
            'lead_follow_ups',
            LeadFollowUpJob::class,
            fn (LeadFollowUpJob $job) => $job->force && $job->lead->getId() === $this->lead->getId()
        );
    }

    private function createPipeline(int $appId): Pipeline
    {
        return Pipeline::create([
            'apps_id' => $appId,
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => fake()->uuid(),
            'slug' => 'new_lead_sms_' . fake()->unique()->numerify('######'),
            'is_default' => 0,
        ]);
    }

    private function createStage(string $stepKey, string $template, int $weight): PipelineStage
    {
        return PipelineStage::create([
            'pipelines_id' => $this->pipeline->getId(),
            'name' => $stepKey,
            'weight' => $weight,
            'config' => [
                'stage_meta' => ['step_key' => $stepKey],
                'follow_up' => [
                    'enabled' => true,
                    'mode' => 'time_based',
                    'time_based' => ['interval_minutes' => 60],
                    'max_retries' => 1,
                    'exhausted_action' => 'stop',
                    'channels' => [
                        ['type' => 'sms', 'enabled' => true, 'template_name' => $template],
                    ],
                ],
            ],
        ]);
    }

    private function addInboundMessage(Carbon $at): void
    {
        $channel = Channel::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'SMS',
            'slug' => 'test-sms-' . $this->lead->getId(),
            'description' => 'test',
            'entity_namespace' => People::class,
            'entity_id' => $this->lead->people_id,
        ]);
        $messageType = MessageType::firstOrCreate(
            [
                'apps_id' => $this->testApp->getId(),
                'languages_id' => 1,
                'verb' => 'text',
            ],
            ['name' => 'Text']
        );
        $message = Message::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => [
                'content' => 'hi',
                'from_me' => false,
            ],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $channel->addMessage($message);
    }

    private function receiver(array $configuration): ReceiverWebhook
    {
        return ReceiverWebhook::factory()->create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'configuration' => $configuration,
        ]);
    }

    private function request(array $headers, array $body): Request
    {
        $request = Request::create('/api/receiver/test', 'POST', $body);
        $request->headers->add($headers);

        return $request;
    }
}
