<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Carbon\Carbon;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class FollowUpPromptQueryTest extends TestCase
{
    public function testFollowUpPromptQuery(): void
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
            'form_fields' => '{"personal":{"type":"object","required":1}}',
            'form_config' => '{"require_credit-app_signature":true}',
        ], [
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
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, ['New Years Day', 'Christmas Day']);
        $company->set('adf_sources', [
            [
                'Source' => 'default',
                'Sub_Source' => 'default',
                'Backend' => 'ADVANCED_REQUEST',
                'Default_Completion_Status' => 'Incomplete',
                'is_default' => true,
            ],
        ]);

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Type',
            'description' => 'Test Description',
            'is_active' => 1,
        ]);
        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Test Source',
            'description' => 'Test Description',
            'is_active' => 1,
            'leads_types_id' => $leadType->id,
        ]);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $lead->leads_types_id = $leadType->id;
        $lead->leads_sources_id = $leadSource->id;
        $lead->saveOrFail();
        $lead->people->addEmail(fake()->email);
        $lead->people->addPhone(fake()->phoneNumber);

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
            'message' => 'Hello, I am interested in a vehicle',
        ]);
        $message = new CreateMessageAction($dto)->execute();

        $pipelineStage = $lead->getCurrentPipelineStage();
        $timezone = $lead->company->get('timezone') ?? 'UTC';
        $now = Carbon::now($timezone)->subHour(2);

        $channel = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Lead ' . $lead->getId() . ' Session',
            'slug' => 'lead_' . $lead->getId() . '_session_' . fake()->uuid(),
        ]);
        $channel = (new CreateChannelAction($channel))->execute();

        $message->created_at = $now;
        $message->saveOrFail();
        $channel->addMessage($message);

        Agent::factory()->create([
            'name' => 'firstMessageEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'role' => [],
        ]);

        $agent = Agent::factory()->create([
            'name' => 'FollowUpEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'role' => [
                'background' => [
                    'Create a follow up message for the lead. Templates: {{ $templates }}. Day: {{ $day }}.',
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

        $query = '
            query FollowUpPrompt(
                $lead_id: ID!
                $pipeline_stage_id: ID!
                $session_id: String!
                $message_template: String!
                $day: Float!
            ) {
                followUpPrompt(
                    lead_id: $lead_id
                    pipeline_stage_id: $pipeline_stage_id
                    session_id: $session_id
                    message_template: $message_template
                    day: $day
                )
            }
        ';

        $variables = [
            'lead_id' => (string) $lead->getId(),
            'pipeline_stage_id' => (string) $pipelineStage->getId(),
            'session_id' => $session->uuid,
            'message_template' => 'Hi {{$lead->firstname}}, just following up!',
            'day' => 1.0,
        ];

        $response = $this->graphQL($query, $variables);
        $response->assertSuccessful();

        $prompt = $response->json('data.followUpPrompt');
        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }
}
