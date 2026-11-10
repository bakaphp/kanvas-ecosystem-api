<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\AIAssistAgentResponderAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

/**
 * The lead + people channel dual-write exists so customer deliveries show up in the deal view.
 * AI Assist is the rep talking to the agent about the lead, so its replies must stay on the
 * AI Assist channel and never reach the customer-facing lead conversation.
 */
class AIAssistReplyStaysOnItsChannelTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testAiAssistReplyIsAttachedOnlyToTheAiAssistChannel(): void
    {
        [$channel, $inbound, $agent, $lead, $people] = $this->scaffold('ai-assist');

        $action = new class ($channel, $inbound, $agent) extends AIAssistAgentResponderAction {
            public function reply(string $text, Message $inbound, Channel $channel): Message
            {
                return $this->createMessage($text, 'ai-assist', $inbound, $channel);
            }
        };

        $reply = $action->reply('Here is where things stand.', $inbound, $channel);

        $this->assertSame([$channel->getId()], $this->channelIdsFor($reply));
        $this->assertNull($this->leadChannel($lead), 'lead channel must not be created for an AI Assist reply');
        $this->assertNull($this->peopleChannel($people), 'people channel must not be created for an AI Assist reply');
    }

    public function testCustomerFacingReplyStillMirrorsToTheLeadAndPeopleChannels(): void
    {
        [$channel, $inbound, $agent, $lead, $people] = $this->scaffold('whatsapp');

        $action = new class ($channel, $inbound, $agent) extends BaseAgentChannelReplyAction {
            public function reply(string $text, Message $inbound, Channel $channel): Message
            {
                return $this->createMessage($text, 'test', $inbound, $channel);
            }
        };

        $reply = $action->reply('Hi there', $inbound, $channel);
        $channelIds = $this->channelIdsFor($reply);

        $this->assertContains($channel->getId(), $channelIds);
        $this->assertContains($this->leadChannel($lead)?->getId(), $channelIds);
        $this->assertContains($this->peopleChannel($people)?->getId(), $channelIds);
    }

    /**
     * @return array{Channel, Message, Agent, Lead, People}
     */
    private function scaffold(string $verb): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $people = People::getById((int) $lead->people_id);

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => $verb],
            ['name' => $verb]
        );

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => $verb . '-stays-' . $lead->getId()],
            ['name' => 'Test', 'description' => 't', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'Where do we stand with this lead?', 'from_me' => false, 'chat_jid' => $verb],
            ]);
        $inbound->addEntity($lead);
        $inbound->addEntity($people);
        $channel->addMessage($inbound);

        $agentType = AgentType::factory()->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);
        $agent = Agent::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create([
            'name' => 'TestAgent',
            'agent_type_id' => $agentType->getId(),
            'user_id' => $user->getId(),
        ]);

        return [$channel, $inbound->fresh(), $agent, $lead, $people];
    }

    /**
     * @return list<int>
     */
    private function channelIdsFor(Message $message): array
    {
        return $message->fresh()->channels()->pluck('channels.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function leadChannel(Lead $lead): ?Channel
    {
        return Channel::fromApp($lead->app)->where('slug', new LeadChannelService()->slugFor($lead))->first();
    }

    private function peopleChannel(People $people): ?Channel
    {
        return Channel::fromApp($people->app)->where('slug', new PeopleChannelService()->slugFor($people))->first();
    }
}
