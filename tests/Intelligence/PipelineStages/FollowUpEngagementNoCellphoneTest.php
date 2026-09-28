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

/**
 * Regression for Frederik's second report: a lead with only a landline "Phone" (no cellphone)
 * on the sms channel must be skipped with reason `no_reachable_contact` BEFORE any message is
 * generated — instead of the AI producing + persisting a phantom message and then
 * SendMessageToLeadAction throwing LeadMissingContactException (which also blocked stage advance).
 */
class FollowUpEngagementNoCellphoneTest extends TestCase
{
    public function testSkipsSmsWhenLeadHasNoCellphone(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            [$lead, $channel] = $this->makeLandlineOnlyLead();

            $messageCountBefore = $channel->messages()->count();

            $action = new FollowUpEngagementAction($lead);
            $result = $action->execute();

            $this->assertNull($result);
            $this->assertContains('no_reachable_contact', array_column($action->getSkippedReasons(), 'reason'));
            $this->assertSame($messageCountBefore, $channel->messages()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: Lead, 1: \Kanvas\Social\Channels\Models\Channel}
     */
    private function makeLandlineOnlyLead(): array
    {
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

        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        // Landline only — NO cellphone. This is the reproduction: SMS is not deliverable.
        // The Lead factory seeds a cellphone; strip it so getCellPhones() is genuinely empty.
        $lead->people->getCellPhones()->each(fn ($contact) => $contact->forceDelete());
        $lead->people->addPhone(fake()->phoneNumber);

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

        $inbound = new CreateMessageAction(
            MessageInput::from([
                'app' => $app,
                'company' => $company,
                'user' => $user,
                'type' => $smsType,
                'message' => [
                    'content' => 'Still thinking about it',
                    'from_me' => false,
                ],
            ])
        )->execute();
        $inbound->created_at = Carbon::now('America/Los_Angeles')->subHours(3);
        $inbound->saveOrFail();
        $channel->addMessage($inbound);

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

        return [$lead, $channel];
    }
}
