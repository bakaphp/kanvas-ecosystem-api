<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Carbon\Carbon;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;
use Kanvas\Intelligence\PipelinesStages\Actions\FollowUpEngagementAction;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class FollowUpEngagementActionTest extends TestCase
{
    public function testNotificationEngagementAction(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $actions = [[
            'id' => 7,
            'name' => 'credit-app',
            'description' => 'Credit App',
            'title' => 'Credit App',
            'enable' => true,
            'icon' => '',
            'reasonEn' => 'apply for financing',
            'reasonEs' => 'apply for financing',
            'form_fields' => '{"personal":{"type":"object","required":1},"housing":{"type":"object","required":1},"financial":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ],[
            'id' => 8,
            'name' => 'view-vehicle',
            'description' => 'View Vehicle',
            'title' => 'View Vehicle',
            'enable' => true,
            'icon' => '',
            'reasonEn' => 'follow up message to lead',
            'reasonEs' => 'follow up message to lead',
            'form_fields' => '{}',
            'form_config' => '{}',
        ]];
        new Setup($app, $user, $company, $actions)->run();

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
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, ['New Year’s Day', 'Christmas Day', 'Independence Day', 'Labor Day', 'Thanksgiving Day', 'Christmas Eve']);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $lead->people->addEmail(fake()->email);
        $lead->people->addPhone(fake()->phoneNumber);

        $config = [
            'notification_engagement_rules' => [
                'minutes_no_response' => 60,
                'day' => 1,
                'templates' => [
                    'sms' => 'Hi [Customer Name], this is [Rep Name] from [Dealership Name]! 👋 Thanks for checking us out online. I’d love to help you find the perfect vehicle for your family and a payment that feels comfortable. When’s a good time to connect?',
                    'email' => 'Good morning [Customer Name]! We’d love to have you stop by this week 🚗💨. Our team will make the process simple and stress-free. Would [day/time] work for a quick visit?',
                ],
            ],
        ];
        $messageType = MessageType::firstOrCreate([
            'apps_id' => $app->getId(),
            'languages_id' => 1,
            'name' => 'AI Generated Message',
        ]);
        $dto = MessageInput::from([
            'app' => $app,
            'company' => $company,
            'user' => $user,
            'type' => $messageType,
            'message' => 'Greeting I want to buy a gaming pc',
        ]);
        $message = new CreateMessageAction($dto)->execute();

        $pipelineStage = $lead->getCurrentPipelineStage();
        $pipelineStage->config = $config;
        $pipelineStage->saveOrFail();

        // Create FollowUp infrastructure so FollowUpEngagementAction can find it
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
            'send_message' => false,
        ]);

        FollowUpTemplate::create([
            'follow_up_days_id' => $followUpDay->id,
            'communication_channel' => 'sms',
            'name' => 'SMS Follow Up',
            'template' => 'Hi {{$lead->firstname}}, thanks for your interest! Would you like to schedule a visit?',
        ]);

        FollowUpTemplate::create([
            'follow_up_days_id' => $followUpDay->id,
            'communication_channel' => 'email',
            'name' => 'Email Follow Up',
            'template' => 'Hi {{$lead->firstname}}, thanks for your interest! Would you like to schedule a visit?',
        ]);

        $timezone = $lead->company->get('timezone') ?? 'UTC';
        $now = Carbon::now($timezone)->subHour(2);

        $channel = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Lead ' . $lead->getId() . ' Session',
            'slug' => 'lead_' . $lead->getId() . '_session',
        ]);
        $channel = (new CreateChannelAction($channel))->execute();
        $emailChannel = $channel->replicate();
        $emailChannel->save();

        $message->created_at = $now;
        $message->saveOrFail();

        $channel->addMessage($message);
        $emailChannel->addMessage($message);

        $agent = Agent::factory()->create([
            'name' => 'firstMessageEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'user_id' => $user->getId(),
            'role' => [],
        ]);
        
        $agent = Agent::factory()->create([
            'name' => 'FollowUpEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'user_id' => $user->getId(),
            'role' => [
                'background' => [
                    'Using the json take the conversation history and the context to create a friendly message to re-engage the customer based on the day and the day template, just give me the message. 
                    conversation_history {!! json_encode($conversation_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
                    context {!! json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
                    ',
                ],
                'steps' => [
                    'Using the json take the conversation history and the context to create a friendly message to re-engage the customer based on the day and the day template, just give me the message. 
                    conversation_history {!! json_encode($conversation_history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
                    context {!! json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
                    ',
                ],
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

        $sessionEmail = $session->replicate();
        $sessionEmail->uuid = 'email' . fake()->email();
        $sessionEmail->channel_id = $emailChannel->id;
        $sessionEmail->save();

        // Fake Prism AI response so the test doesn't depend on external AI services
        $fakeStructuredData = [
            'message' => 'Hi there! Just following up on your interest. Would you like to schedule a visit?',
            'should_respond' => true,
        ];

        $fakeResponse = StructuredResponseFake::make()
            ->withText(json_encode($fakeStructuredData, JSON_THROW_ON_ERROR))
            ->withStructured($fakeStructuredData)
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(100, 50))
            ->withMeta(new Meta('fake-response-id', 'gemini-2.5-pro'));

        Prism::fake([$fakeResponse, $fakeResponse]);

        $message = new FollowUpEngagementAction($lead)->execute();

        $this->assertIsArray($message);
    }
}
