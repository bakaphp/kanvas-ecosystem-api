<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Workflow\Events\StopEvent;
use RuntimeException;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;

class ConversationMemoryNodeTest extends TestCase
{
    private const array METADATA = ['apps_id' => 1, 'companies_id' => 42, 'agent_id' => 7, 'users_id' => 9];

    public function testATurnIsWrittenAsOneDocumentWithTheTenantMetadata(): void
    {
        $store = SharedCompanyMemory::newStore();
        $reply = new AssistantMessage('The Q4 launch is on November 12, confirmed with marketing yesterday.');

        $event = $this->remember($store, 'When is the Q4 launch?', $reply);

        $this->assertInstanceOf(StopEvent::class, $event);
        $documents = SharedCompanyMemory::all($store);
        $this->assertCount(1, $documents);
        $document = $documents[0];
        $this->assertSame("User: When is the Q4 launch?\nAssistant: {$reply->getContent()}", $document->getContent());
        $this->assertSame('conversation:' . $reply->getId(), $document->getId(), 'Keyed by the reply so a replay upserts');
        $this->assertSame('conversation', $document->getSourceType());
        $this->assertSame('thread-1', $document->getSourceName());
        $this->assertSame(42, $document->getMetadata()['companies_id']);
        $this->assertSame(7, $document->getMetadata()['agent_id']);
        $this->assertSame((string) $reply->getId(), $document->getMetadata()['source_id']);
        $this->assertGreaterThan(0, $document->getMetadata()['created_at']);
    }

    public function testAShortTurnIsNotWorthRemembering(): void
    {
        $store = SharedCompanyMemory::newStore();

        $this->remember($store, 'hi', new AssistantMessage('Hello!'));

        $this->assertSame([], SharedCompanyMemory::all($store));
    }

    public function testTheAgentToAgentGuidanceIsNotRemembered(): void
    {
        $store = SharedCompanyMemory::newStore();
        $mention = 'Task #10484 has been unblocked and marked as done.' . AgentTurnResponse::noOpGuidance();

        $this->remember($store, $mention, new AssistantMessage('Noted, I will pick up the next task on the plan now.'));

        $content = SharedCompanyMemory::all($store)[0]->getContent();

        $this->assertStringStartsWith('User: Task #10484 has been unblocked and marked as done.', $content);
        $this->assertStringNotContainsString('NO_UPDATE', $content, 'Recalled later, the instruction made an agent answer a human with NO_UPDATE');
    }

    public function testADeclinedTurnIsNotAMemory(): void
    {
        $store = SharedCompanyMemory::newStore();

        $this->remember($store, 'Here is the 3-bullet summary of CRM metrics for Task #10486, all done.', new AssistantMessage('NO_UPDATE'));

        $this->assertSame([], SharedCompanyMemory::all($store));
    }

    public function testAToolCallAnswerIsNotAMemory(): void
    {
        $store = SharedCompanyMemory::newStore();
        $call = new ToolCallMessage(null, [ToolCall::make('search_leads', 'c-1', ['query' => 'acme corporation details'])]);

        $this->remember($store, 'Find everything we know about Acme Corporation, please.', $call);

        $this->assertSame([], SharedCompanyMemory::all($store));
    }

    public function testTheTurnEndsNormallyWhenTheStoreFails(): void
    {
        $store = new class () extends MemoryVectorStore {
            public int $attempts = 0;

            public function addDocument(Document $document): VectorStoreInterface
            {
                $this->attempts++;

                throw new RuntimeException('Typesense is down');
            }
        };

        $event = $this->remember($store, 'A question long enough to qualify for memory', new AssistantMessage('An answer long enough to qualify as well.'));

        $this->assertInstanceOf(StopEvent::class, $event, 'The reply is already computed; a failed write never fails the turn');
        $this->assertSame(1, $store->attempts, 'The write was attempted, not skipped');
    }

    private function remember(MemoryVectorStore $store, string $question, Message $reply): StopEvent
    {
        $node = new ConversationMemoryNode(
            $store,
            new ConstantEmbeddingsProvider(),
            self::METADATA,
            minChars: 40,
        );

        return $node(new AgentOutputEvent(), $this->state($question, $reply), $this->resources());
    }

    private function state(string $question, Message $reply): AgentState
    {
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('test'), [new UserMessage($question)]);

        return $state->setResponse(new ProviderResponse(message: $reply));
    }

    private function resources(): AgentResources
    {
        return new AgentResources(
            new FakeNeuronProvider(),
            new ChatHistory(new InMemoryMessageStore(), 'thread-1'),
            new SystemMessage('test'),
            new ToolRegistry([]),
        );
    }
}
