<?php

declare(strict_types=1);

namespace Tests\Communication\Workflows;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\ConversationAgentEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Workflows\AgentChannelResponderActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\Stubs\Intelligence\StructuredNeuronAgentStub;
use Tests\TestCase;

/**
 * The one responder: whatever medium a conversation arrived on, the agent's answer leaves through
 * that conversation's connection and is recorded on the reply the agent filed.
 */
final class AgentChannelResponderActivityTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
        $this->currentCompany()->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value);

        parent::tearDown();
    }

    public function testTheAnswerLeavesThroughTheConversationsConnection(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550600');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550601');

        $result = $this->respond($channel, $inbound);
        $this->assertArrayNotHasKey('error', $result, json_encode($result));

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();
        $reply = Message::query()->findOrFail($delivery->message_id);

        // The first text to a number carries the opt-out clause.
        $this->assertSame("Hola Mundo\n\nReply STOP to opt out.", $result['response'] ?? null);
        $this->assertSame($result['response'], $this->transport->sent[0]['text']);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame('+18095550601', $delivery->to_address);
        $this->assertTrue((bool) $reply->message['from_ia']);
        $this->assertSame($inbound->messageType->verb, $reply->messageType->verb, 'filed as the kind of message it answers');
        $this->assertCount(1, $this->transport->sent);
    }

    public function testStructuredRepliesKeepTheirEnvelopeOnTheFiledMessage(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550607');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550608');

        $result = $this->respond($channel, $inbound, StructuredNeuronAgentStub::class);
        $this->assertArrayNotHasKey('error', $result, json_encode($result));

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();
        $reply = Message::getById($delivery->message_id, $this->currentApp());

        $this->assertSame("Hola Mundo\n\nReply STOP to opt out.", $this->transport->sent[0]['text']);
        $this->assertSame('Education accelerates classroom construction in El Seibo', $reply->message['response_json']['title'] ?? null);
        $this->assertSame(['National', 'Education'], $reply->message['response_json']['categories'] ?? null);
        $this->assertSame('draft', $reply->message['response_json']['status'] ?? null);
    }

    public function testAnAgentMappedFromAnotherCompanyCannotAnswer(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550609');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550610');
        $agent = $this->agent(SalesNeuronAgentStub::class);
        $agent->forceFill(['companies_id' => $this->otherCompany()->getId()])->saveOrFail();

        $result = new AgentChannelResponderActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute($channel, $this->currentApp(), [
            'message' => $inbound,
            'channelAgentMapping' => ['+18095550610' => ['agent_id' => $agent->getId()]],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $this->transport->sent);
        $this->assertFalse(Delivery::query()->where('connection_id', $connection->getId())->exists());
    }

    /**
     * A message from before connections, or from a provider still on its own responder, is that
     * provider's responder's to answer. This one stays out of it.
     */
    public function testAMessageOnNoConnectionIsLeftAlone(): void
    {
        $lead = $this->leadOf($this->personWithCellphone('+18095550602'));
        $channel = $this->conversationChannel($lead);
        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'fake-sms',
            ['content' => 'Hi', 'from_me' => false]
        );

        $result = $this->respond($channel, $inbound);

        $this->assertSame('The message did not arrive on a connection', $result['message']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testATurnForAConnectionSwitchedOffSendsNothing(): void
    {
        $connection = $this->defaultSmsConnection(number: '+18095550603');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550604');
        $connection->forceFill(['enabled' => false])->saveOrFail();

        $result = $this->respond($channel, $inbound);

        $this->assertStringContainsString('connection_disabled', $result['message']);
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * The recipient unsubscribed with the provider directly. Expected, so it is a note on the lead
     * and not a fault the activity retries into the provider again.
     */
    public function testARecipientWhoUnsubscribedWithTheProviderLeavesANote(): void
    {
        $this->transport->sendResult = TransportResult::failed('21610', 'unsubscribed', recipientOptedOut: true);
        $connection = $this->defaultSmsConnection(number: '+18095550605');
        [$channel, $inbound] = $this->inboundOn($connection, '+18095550606');

        $this->respond($channel, $inbound);

        $this->assertTrue(Delivery::query()->where('connection_id', $connection->getId())->sole()->recipientOptedOut());
        $this->assertTrue(
            Message::query()
                ->where('companies_id', $this->currentCompany()->getId())
                ->where('message', 'like', '%has opted out of messages%')
                ->exists()
        );
    }

    /**
     * @return array{Channel, Message}
     */
    private function inboundOn(Connection $connection, string $from): array
    {
        $lead = $this->leadOf($this->personWithCellphone($from));
        $channel = $this->conversationChannel($lead);

        $inbound = $this->fileInConversation(
            $channel,
            $lead,
            'fake-sms',
            [
                'content' => 'Is it still available?',
                'from_me' => false,
                'chat_jid' => $from,
                'communication' => [
                    'connection_id' => $connection->getId(),
                    'channel' => ChannelTypeEnum::SMS->value,
                    'thread' => $from,
                    'activation_version' => $connection->activation_version,
                ],
            ]
        );

        return [$channel, $inbound];
    }

    private function respond(Channel $channel, Message $inbound, string $handler = SalesNeuronAgentStub::class): array
    {
        $this->agent($handler);

        return new AgentChannelResponderActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute($channel, $this->currentApp(), ['message' => $inbound]);
    }

    private function agent(string $handler): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->currentApp()->getId())
            ->create(['name' => 'Sales (Neuron Test)', 'provider' => 'neuron', 'handler' => $handler]);

        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'name' => ConversationAgentEnum::DEFAULT_NAME->value,
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
