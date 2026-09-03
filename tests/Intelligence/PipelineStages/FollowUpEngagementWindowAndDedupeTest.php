<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Carbon\Carbon;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline as ActionEnginePipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage as ActionEnginePipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

/**
 * Covers the behavior Fred asked for: the follow-up keeps messaging for up to 90 days (no
 * unanswered cap), but never resends the same copy — if the generated message duplicates a
 * prior one, the day is marked complete by advancing the pipeline stage instead of sending.
 */
class FollowUpEngagementWindowAndDedupeTest extends TestCase
{
    public function testSkipsWhenLeadIsPastNinetyDayWindow(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            [$lead, $channel] = $this->makeLead();

            // Lead created 91 days ago — outside the 90-day follow-up window.
            $lead->created_at = Carbon::now()->subDays(91);
            $lead->saveOrFail();

            $messageCountBefore = $channel->messages()->count();

            $action = new FollowUpEngagementAction($lead);
            $result = $action->execute();

            $this->assertNull($result);
            $this->assertContains('follow_up_window_expired', array_column($action->getSkippedReasons(), 'reason'));
            $this->assertSame($messageCountBefore, $channel->messages()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAdvancesStageInsteadOfResendingWhenMessageIsDuplicate(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            [$lead, $channel, $pipelineStage, $session] = $this->makeLead(withGeneration: true);

            $duplicateText = 'Hi David, would you like to schedule a visit this week?';

            // A prior follow-up we already sent, two hours ago.
            $smsType = MessageType::firstOrCreate([
                'apps_id' => $lead->apps_id,
                'name' => 'twilio-sms',
                'verb' => 'twilio-sms',
            ], [
                'languages_id' => 1,
            ]);
            $prior = new CreateMessageAction(
                MessageInput::from([
                    'app' => $lead->app,
                    'company' => $lead->company,
                    'user' => auth()->user(),
                    'type' => $smsType,
                    'message' => [
                        'content' => $duplicateText,
                        'from_me' => true,
                    ],
                ])
            )->execute();
            $prior->created_at = Carbon::now('America/Los_Angeles')->subHours(2);
            $prior->saveOrFail();
            $channel->addMessage($prior);

            // Guarantee a next stage exists so moveToNextPipelineStage() can advance.
            PipelineStage::create([
                'pipelines_id' => $pipelineStage->pipelines_id,
                'name' => 'Day 2',
                'weight' => (int) $pipelineStage->weight + 1,
                'is_deleted' => 0,
            ]);

            // The agent regenerates the SAME copy — it must NOT be resent.
            StructuredAnonymousAgent::fake([
                ['message' => $duplicateText, 'should_respond' => true],
            ]);

            $messageCountBefore = $channel->messages()->count();
            $originalStageId = (int) $lead->pipeline_stage_id;

            $action = new FollowUpEngagementAction($lead);
            $result = $action->execute();

            $this->assertNull($result);
            $this->assertContains('duplicate_message_advanced_stage', array_column($action->getSkippedReasons(), 'reason'));
            $this->assertSame($messageCountBefore, $channel->messages()->count(), 'Duplicate copy must not be persisted/sent');
            $this->assertNotSame($originalStageId, (int) $lead->pipeline_stage_id, 'Pipeline must advance instead of resending');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: Lead, 1: Channel, 2: PipelineStage, 3: \Kanvas\Intelligence\Sessions\Models\Session}
     */
    private function makeLead(bool $withGeneration = false): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        if ($withGeneration) {
            new InventorySetup($app, $user, $company)->run();

            // CreateMessageFollowUpAction builds a view-vehicle engagement while composing the
            // prompt; without this pipeline/action it throws and the send is silently skipped.
            $actionEnginePipeline = ActionEnginePipeline::firstOrCreate([
                'slug' => 'view-vehicle',
                'companies_id' => $company->getId(),
                'apps_id' => $app->getId(),
            ], [
                'users_id' => $user->getId(),
                'name' => 'view-vehicle',
                'weight' => 0,
            ]);

            ActionEnginePipelineStage::firstOrCreate([
                'pipelines_id' => $actionEnginePipeline->getId(),
                'slug' => 'sent',
            ], [
                'name' => 'Sent',
                'weight' => 1,
            ]);

            $viewVehicleAction = Action::firstOrCreate([
                'slug' => 'view-vehicle',
            ], [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
                'pipelines_id' => $actionEnginePipeline->getId(),
                'name' => 'view-vehicle',
            ]);

            CompanyAction::firstOrCreate([
                'actions_id' => $viewVehicleAction->getId(),
                'companies_id' => $company->getId(),
                'apps_id' => $app->getId(),
            ], [
                'users_id' => $user->getId(),
                'companies_branches_id' => $company->defaultBranch->getId(),
                'pipelines_id' => $actionEnginePipeline->getId(),
                'name' => 'view-vehicle',
            ]);
        }

        $company->set('timezone', 'America/Los_Angeles');
        $workHours = [
            'Monday' => '00:00 - 23:59',
            'Tuesday' => '00:00 - 23:59',
            'Wednesday' => '00:00 - 23:59',
            'Thursday' => '00:00 - 23:59',
            'Friday' => '00:00 - 23:59',
            'Saturday' => '00:00 - 23:59',
            'Sunday' => '00:00 - 23:59',
        ];
        $company->set(ConfigurationEnum::WORKING_HOURS->value, $workHours);
        $company->set(ConfigurationEnum::WORKING_DAYS->value, array_keys($workHours));
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, ['New Year\'s Day']);
        $company->set('adf_sources', [
            [
                'Source' => 'Default',
                'Sub_Source' => 'Website',
                'Backend' => 'ADVANCED_REQUEST',
                'Default_Completion_Status' => 'Incomplete',
                'is_default' => true,
            ],
        ]);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $leadType = LeadType::firstOrCreate([
            'name' => 'Internet',
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ], [
            'description' => 'Internet Lead',
        ]);
        $lead->leads_types_id = $leadType->id;

        $leadSource = LeadSource::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Default',
        ], [
            'description' => 'Default Source',
            'is_active' => 1,
            'leads_types_id' => $leadType->id,
        ]);
        $lead->leads_sources_id = $leadSource->id;
        $lead->leads_status_id = 1;
        $lead->saveOrFail();

        $lead->setContactStatus(LeadGroupStatusEnum::WAITING);

        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        $lead->people->addCellPhone(fake()->phoneNumber);

        $pipelineStage = $lead->getCurrentPipelineStage();

        if (! $lead->pipeline_id) {
            $lead->pipeline_id = $pipelineStage->pipelines_id;
            $lead->saveOrFail();
        }

        $followUp = FollowUp::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'pipelines_id' => $pipelineStage->pipelines_id,
            'follow_up_type' => FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
            'name' => 'Test Lead Follow Up',
        ]);

        $followUpDay = FollowUpDay::create([
            'follow_ups_id' => $followUp->id,
            'pipeline_stages_id' => $pipelineStage->getId(),
            'name' => 'Day 1',
            'time_value' => 60,
            'time_unit' => 'minutes',
            'weight' => 1,
            'calendar_day' => false,
            'send_message' => true,
        ]);

        FollowUpTemplate::create([
            'follow_up_days_id' => $followUpDay->id,
            'communication_channel' => 'sms',
            'name' => 'SMS Follow Up',
            'template' => 'Hi {{$lead->firstname}}, would you like to schedule a visit?',
        ]);

        $channelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Lead ' . $lead->getId() . ' Session',
            'slug' => 'lead_' . $lead->getId() . '_session',
        ]);
        $channel = new CreateChannelAction($channelDto)->execute();

        $agent = Agent::factory()->create([
            'name' => 'FollowUpEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'user_id' => $user->getId(),
            'role' => [
                'background' => ['just give me the message'],
                'steps' => ['just give me the message'],
            ],
        ]);

        $sessionDto = Session::from([
            'agent' => $agent,
            'channel' => $channel,
            'app' => $app,
            'company' => $lead->company,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'user' => $lead->user->toArray(),
            'canal_id' => 'lead_' . $lead->getId() . '_session',
            'userModel' => $user,
        ]);

        $session = new CreateSessionAction($sessionDto)->execute();
        $session->content = new CreateContentSessionAction($session)->execute();
        $session->uuid = 'twilio-' . fake()->phoneNumber();
        $session->saveOrFail();

        return [$lead, $channel, $pipelineStage, $session];
    }
}
