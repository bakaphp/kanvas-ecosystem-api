<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
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
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class FollowUpEngagementV2UnansweredCapTest extends TestCase
{
    /**
     * Regression for the production symptom Frederik reported: a v2 lead parked on its
     * terminal day-stage (moveToNextPipelineStage() silently no-ops) kept getting a
     * near-identical twilio-sms nudge every cron tick — ~20 sends, same day, never advancing.
     * Once our own outbound has gone unanswered MAX_UNANSWERED_FOLLOW_UPS times in a row the
     * action must skip with reason `max_unanswered_follow_ups` and send nothing further.
     */
    public function testV2SmsSkipsAfterCapOfUnansweredOutbound(): void
    {
        Carbon::setTestNow(
            Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles')
        );

        try {
            $user = auth()->user();
            $company = $user->getCurrentCompany();
            $app = app(Apps::class);

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

            $followUpKey = new LeadConfigurationService(true)->getFollowUpModeKey($lead);
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

            $smsType = MessageType::firstOrCreate([
                'apps_id' => $app->getId(),
                'name' => 'twilio-sms',
                'verb' => 'twilio-sms',
            ], [
                'languages_id' => 1,
            ]);

            $timezone = $company->get('timezone') ?? 'UTC';
            for ($i = 3; $i >= 1; $i--) {
                $dto = MessageInput::from([
                    'app' => $app,
                    'company' => $company,
                    'user' => $user,
                    'type' => $smsType,
                    'message' => [
                        'content' => "Follow up attempt {$i}",
                        'from_me' => true,
                    ],
                ]);
                $outbound = new CreateMessageAction($dto)->execute();
                $outbound->created_at = Carbon::now($timezone)->subHours($i);
                $outbound->saveOrFail();
                $channel->addMessage($outbound);
            }

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

            $messageCountBefore = $channel->messages()->count();

            $action = new FollowUpEngagementAction($lead, null, true);
            $result = $action->execute();

            $this->assertNull($result, 'No follow-up should be sent once the unanswered cap is hit');

            $skipReasons = array_column($action->getSkippedReasons(), 'reason');
            $this->assertContains('max_unanswered_follow_ups', $skipReasons);

            $this->assertSame(
                $messageCountBefore,
                $channel->messages()->count(),
                'No new outbound message should be created when the unanswered cap is hit'
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
