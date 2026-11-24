<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Chat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * After a turn the chat action copies the session channel onto the lead and people channels so the
 * rollup history sees it. An AI Assist session's channel is staff talking to the agent about the lead,
 * so that copy must not happen; a customer channel keeps it.
 */
class RunNeuronChatBackfillSkipsInternalChannelTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    public function testAnAiAssistSessionDoesNotCopyItsChannelOntoTheLeadChannel(): void
    {
        [$lead, $session, $agent] = $this->scaffold(ChannelNameEnum::AI_ASSIST->value, 'ai-assist');

        $this->runTurn($agent, $session);

        $this->assertNull($this->leadChannel($lead), 'AI Assist chatter must stay off the lead conversation');
    }

    public function testACustomerSessionStillCopiesItsChannelOntoTheLeadChannel(): void
    {
        [$lead, $session, $agent, $inbound] = $this->scaffold('WhatsApp', 'whatsapp');

        $this->runTurn($agent, $session);

        $leadChannel = $this->leadChannel($lead);
        $this->assertNotNull($leadChannel);
        $this->assertTrue($leadChannel->messages()->where('messages.id', $inbound->getId())->exists());
    }

    private function runTurn(Agent $agent, Session $session): void
    {
        $user = auth()->user();
        $handler = new CapturingNeuronAgentStub();
        $handler->setConfiguration(agent: $agent, user: $user);

        new RunNeuronChatAction(
            agent: $agent,
            session: $session,
            message: 'Where do we stand with this lead?',
            app: app(Apps::class),
            user: $user,
            handler: $handler,
        )->execute();
    }

    /**
     * @return array{Lead, Session, Agent, Message}
     */
    private function scaffold(string $channelName, string $verb): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = $this->makeAgentFor($user);

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => $verb . '-backfill-' . $lead->getId()],
            [
                'name' => $channelName,
                'description' => 't',
                'users_id' => $user->getId(),
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
            ]
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => $verb],
            ['name' => $verb]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'Where do we stand with this lead?', 'from_human' => true, 'chat_jid' => $verb],
            ]);
        $inbound->addEntity($lead);
        $channel->addMessage($inbound);

        $session = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $channel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => SessionChannelService::createCanalId($verb, (string) $lead->getId()),
                'user' => ['name' => $user->displayname, 'id' => $user->getId(), 'email' => $user->email],
                'agent' => $agent,
            ])
        )->execute();

        return [$lead, $session, $agent, $inbound];
    }

    private function leadChannel(Lead $lead): ?Channel
    {
        return Channel::fromApp($lead->app)->where('slug', new LeadChannelService()->slugFor($lead))->first();
    }
}
