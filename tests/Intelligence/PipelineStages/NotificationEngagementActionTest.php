<?php

declare(strict_types=1);

namespace Tests\Intelligence\PipelineStages;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\PipelinesStages\Actions\NotificationEngagementAction;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Tests\TestCase;

class NotificationEngagementActionTest extends TestCase
{
    public function testNotificationEngagementAction(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $config = [
            'notification_engagement_rules' => [
                'minutes_no_response' => 60,
                'prompt' => 'You are a sales agent for gaming store. Create a friendly message to re-engage a customer who has not responded after 1 hours',
            ],
        ];
        $pipelineStage = $lead->getCurrentPipelineStage();
        $pipelineStage->config = $config;
        $pipelineStage->saveOrFail();
        $timezone = $lead->company->get('timezone') ?? 'UTC';
        $now = Carbon::now($timezone)->subHour(2);
        $lead->set(ConfigurationEnum::LAST_MESSAGE_TIME->value, $now->toDateTimeString());

        $agent = Agent::factory()->create([
            'name' => 'firstMessageEngagerAgent',
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'role' => [],
        ]);

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
        $session->content = [
            'first_message' => [
                'message' => 'Hello, how can I help you?',
            ],
        ];
        $session->saveOrFail();
        $message = new NotificationEngagementAction($lead)->execute();
        $this->assertIsArray($message);
    }
}
