<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Support\Setup;
use Kanvas\Intelligence\Agents\Actions\BaseAgentResponderAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;

class BaseAgentResponderActionTest extends TestCase
{
    public function testCreateMessageSetsIsUnResponse(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $channel = new Channel();
        $channel->apps_id = $app->getId();
        $channel->companies_id = $company->getId();
        $channel->users_id = $user->getId();
        $channel->name = 'test-agent-channel-' . time();
        $channel->entity_id = $lead->getId();
        $channel->entity_namespace = Lead::class;
        $channel->saveOrFail();

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $message->addEntity($lead);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $responder = new class ($channel, $message, $agent) extends BaseAgentResponderAction {
            protected string $communicationChannel = 'test';

            public function exposeCreateMessage(
                string $text,
                string $to,
                Message $message,
                Channel $channel,
                ?string $from = null
            ): Message {
                return $this->createMessage($text, $to, $message, $channel, $from);
            }
        };

        $created = $responder->exposeCreateMessage(
            'Hello from AI',
            '+1234567890',
            $message,
            $channel,
        );

        $created->refresh();

        $this->assertEquals(1, $created->is_un_response);
    }
}
