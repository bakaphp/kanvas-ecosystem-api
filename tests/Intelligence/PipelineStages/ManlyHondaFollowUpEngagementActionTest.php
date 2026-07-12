<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline as ActionEnginePipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage as ActionEnginePipelineStage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadGroupStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\PipelinesStages\Actions\ManlyHondaFollowUpEngagementAction;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class ManlyHondaFollowUpEngagementActionTest extends TestCase
{
    public function testManlyHondaFollowUpUsesStageConfigKey(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            // preferred email => sms session must be ignored, only email is sent
            $lead = $this->setupLeadWithSessions(
                lastMessageAgeMinutes: 120,
                stageConfig: [
                    'minutes_no_response' => 60,
                    'internet_used_unreplied' => [
                        'sms' => 'Hi [Customer Name], still interested in that used GMC?',
                        'email' => 'Good morning [Customer Name], the used GMC is still available!',
                    ],
                ],
                preferredChannel: 'email',
            );

            Notification::fake();

            $fake = [
                'message' => 'Hi there! Just following up on the used GMC. Want to schedule a visit?',
                'should_respond' => true,
            ];
            StructuredAnonymousAgent::fake([$fake]);

            $result = new ManlyHondaFollowUpEngagementAction($lead)->execute();

            $this->assertIsArray($result);
            $this->assertArrayHasKey('follow_up_message', $result);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testManlyHondaSkipsWhenNotEnoughTimeSinceLastMessage(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            // last message only 10 minutes ago, stage requires 60 => no send
            $lead = $this->setupLeadWithSessions(
                lastMessageAgeMinutes: 10,
                stageConfig: [
                    'minutes_no_response' => 60,
                    'internet_used_unreplied' => [
                        'sms' => 'Hi [Customer Name], still interested in that used GMC?',
                        'email' => 'Good morning [Customer Name], the used GMC is still available!',
                    ],
                ],
                preferredChannel: 'email',
            );

            Notification::fake();
            StructuredAnonymousAgent::fake([]);

            $result = new ManlyHondaFollowUpEngagementAction($lead)->execute();

            $this->assertNull($result);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testManlyHondaSkipsWhenNoStageConfigKeyMatches(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 14, 12, 0, 0, 'America/Los_Angeles'));

        try {
            $user = auth()->user();
            $company = $user->getCurrentCompany();
            $app = app(Apps::class);

            $company->set('timezone', 'America/Los_Angeles');

            $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
            $lead->leads_status_id = 1;
            $lead->saveOrFail();
            $lead->setContactStatus(LeadGroupStatusEnum::WAITING);

            $pipelineStage = $lead->getCurrentPipelineStage();
            // config only holds a key the lead can never resolve to
            $pipelineStage->config = [
                'minutes_no_response' => 60,
                'internet_new_replied' => ['sms' => 'foo', 'email' => 'bar'],
            ];
            $pipelineStage->saveOrFail();

            $result = new ManlyHondaFollowUpEngagementAction($lead)->execute();

            $this->assertNull($result);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function setupLeadWithSessions(
        int $lastMessageAgeMinutes,
        array $stageConfig,
        ?string $preferredChannel
    ): Lead {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        new InventorySetup($app, $user, $company)->run();

        $company->set('timezone', 'America/Los_Angeles');
        $company->set(ConfigurationEnum::MANLY_HONDA->value, true);
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

        // unreplied (waiting) + used vehicle => key internet_used_unreplied
        $lead->setContactStatus(LeadGroupStatusEnum::WAITING);
        $lead->set(VinSolutionCustomFieldEnum::VEHICLE_OF_INTEREST->value, [
            'year' => 2017,
            'make' => 'GMC',
            'isNew' => false,
        ]);
        if ($preferredChannel !== null) {
            $lead->set(LeadsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, $preferredChannel);
        }

        $lead->people->addEmail(fake()->email);
        $lead->people->addPhone(fake()->phoneNumber);

        $messageType = MessageType::firstOrCreate([
            'apps_id' => $app->getId(),
            'languages_id' => 1,
            'name' => 'AI Generated Message',
        ]);
        MessageType::firstOrCreate([
            'apps_id' => $app->getId(),
            'name' => 'twilio-sms',
            'verb' => 'twilio-sms',
        ], [
            'languages_id' => 1,
        ]);

        $dto = MessageInput::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'type' => $messageType,
            'message' => 'Greeting I want to buy a car',
        ]);
        $message = new CreateMessageAction($dto)->execute();

        $pipelineStage = $lead->getCurrentPipelineStage();
        if (! $lead->pipeline_id) {
            $lead->pipeline_id = $pipelineStage->pipelines_id;
            $lead->saveOrFail();
        }

        $pipelineStage->config = $stageConfig;
        $pipelineStage->saveOrFail();

        $this->setupViewVehicleActionPipeline($app, $user, $company);

        // created_at is persisted and re-read in the app default timezone, so build
        // the message time on that same clock. Using the company timezone here would
        // inject the tz offset on save (never converted back on read) and make even a
        // 10-minute-old message look hours stale.
        $messageTime = Carbon::now()->subMinutes($lastMessageAgeMinutes);

        $smsChannelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Lead ' . $lead->getId() . ' Session',
            'slug' => 'lead_' . $lead->getId() . '_session',
        ]);
        $smsChannel = new CreateChannelAction($smsChannelDto)->execute();
        $emailChannel = $smsChannel->replicate();
        $emailChannel->save();

        $message->created_at = $messageTime;
        $message->saveOrFail();
        $smsChannel->addMessage($message);
        $emailChannel->addMessage($message);

        $agent = Agent::factory()->create([
            'name' => 'FollowUpEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'user_id' => $user->getId(),
            'role' => [
                'background' => ['Re-engage the customer based on the templates and conversation, just give me the message.'],
                'steps' => ['Re-engage the customer based on the templates and conversation, just give me the message.'],
            ],
        ]);

        $smsSession = $this->createSessionForChannel($agent, $smsChannel, $app, $lead, $user, 'twilio-' . fake()->phoneNumber());
        $this->createSessionForChannel($agent, $emailChannel, $app, $lead, $user, 'email' . fake()->email(), $smsSession);

        return $lead;
    }

    private function setupViewVehicleActionPipeline(Apps $app, $user, $company): void
    {
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

    private function createSessionForChannel(Agent $agent, $channel, Apps $app, Lead $lead, $user, string $uuid, $replicateFrom = null)
    {
        if ($replicateFrom !== null) {
            $session = $replicateFrom->replicate();
            $session->uuid = $uuid;
            $session->channel_id = $channel->id;
            $session->save();

            return $session;
        }

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
        $session->uuid = $uuid;
        $session->saveOrFail();

        return $session;
    }
}
