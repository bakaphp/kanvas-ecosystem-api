<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\RAG\Jobs\IndexKnowledgeJob;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\Group;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\StubsTypesenseCredentials;

/**
 * Serial: app settings (Redis). The memory store and embeddings are bound in the container, which is
 * how the commands take them, so no Typesense is needed.
 */
#[Group('serial')]
class AgentMemoryCommandsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use StubsTypesenseCredentials;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'crm'];

    private Apps $kanvasApp;

    private MemoryVectorStore $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->enableAgentMemoryFor($this->kanvasApp, KnowledgeConfigurationEnum::AGENT_MEMORY_RETENTION_DAYS->value);

        $this->memory = SharedCompanyMemory::newStore(50);
        $this->app->instance(VectorStoreInterface::class, $this->memory);
        $this->app->instance(EmbeddingsProviderInterface::class, new ConstantEmbeddingsProvider());
    }

    protected function tearDown(): void
    {
        $this->restoreAgentMemoryFor($this->kanvasApp, KnowledgeConfigurationEnum::AGENT_MEMORY_RETENTION_DAYS->value);

        parent::tearDown();
    }

    public function testReindexWritesPastTurnsKeyedToTheRecord(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = $this->rememberingAgent($user);
        $prospect = People::factory()->withAppId($this->kanvasApp->getId())->withCompanyId($company->getId())->create();
        $thread = $this->seedToolUsingThread($agent, $prospect);

        $this->reindexSinceYesterday();

        $documents = array_values(array_filter(
            SharedCompanyMemory::all($this->memory),
            static fn (Document $document): bool => $document->getSourceName() === $thread,
        ));

        $this->assertCount(1, $documents, 'The tool rows are skipped and the short "ok / Sure." turn is below the minimum');
        $this->assertStringContainsString('Quarterly invoicing, confirmed by their CFO', $documents[0]->getContent());
        $this->assertStringNotContainsString('found', $documents[0]->getContent(), 'The tool result is not the answer');
        $this->assertSame($agent->getId(), $documents[0]->getMetadata()['agent_id']);
        $this->assertSame($company->getId(), $documents[0]->getMetadata()['companies_id']);
        $this->assertSame(People::class, $documents[0]->getMetadata()['entity_type'], 'Tagged with the record so a customer-facing agent can recall it');
        $this->assertSame($prospect->getId(), $documents[0]->getMetadata()['entity_id']);
    }

    public function testReindexQueuesLedgerOutcomes(): void
    {
        Queue::fake();
        $user = auth()->user();
        $agent = $this->rememberingAgent($user);

        $event = new AppendEventAction(new EventData(
            app: $this->kanvasApp,
            company: $user->getCurrentCompany(),
            sourceDomain: 'Test.Memory',
            eventType: 'plan.approved',
            status: EventStatusEnum::INFO,
            actorType: 'Agent',
            actorId: $agent->getId(),
            payload: ['title' => 'Migrate billing to the scheduler'],
        ))->execute();

        $this->reindexSinceYesterday();

        Queue::assertPushed(IndexKnowledgeJob::class, fn (IndexKnowledgeJob $job): bool => $job->entity->id === $event->getId());
    }

    private function rememberingAgent(Users $user): Agent
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create([
            'provider' => 'neuron',
            'handler' => SystemUserAgent::class,
        ]);

        return $this->makeAgentFor($user, $type);
    }

    /**
     * A tool-using turn (call and result rows between the question and the reply) followed by a turn too
     * short to remember. Returns the thread id.
     */
    private function seedToolUsingThread(Agent $agent, People $prospect): string
    {
        $thread = (string) Str::uuid();
        $store = new ConversationMessageStore(
            app: $this->kanvasApp,
            company: $agent->company,
            user: $agent->user,
            agentClass: SystemUserAgent::class,
            sessionId: $thread,
            agent: $agent,
            participant: $prospect,
        );
        $call = ToolCall::make('search_leads', 'c-1', ['query' => 'acme'])->setResult('found');

        foreach ([
            new UserMessage('What did we agree with Acme about invoicing last quarter?'),
            new ToolCallMessage(null, [$call]),
            new ToolResultMessage([$call]),
            new AssistantMessage('Quarterly invoicing, confirmed by their CFO; monthly invoices get rejected.'),
            new UserMessage('ok'),
            new AssistantMessage('Sure.'),
        ] as $message) {
            $store->append($thread, $message);
        }

        return $thread;
    }

    private function reindexSinceYesterday(): void
    {
        $this->artisan('agents:reindex-memory', [
            '--app' => $this->kanvasApp->getId(),
            '--since' => now()->subDay()->toDateString(),
        ])->assertSuccessful();
    }

    public function testPruneDropsAgedConversationAndLedgerMemoryButNeverASavedMemory(): void
    {
        $this->kanvasApp->set(KnowledgeConfigurationEnum::AGENT_MEMORY_RETENTION_DAYS->value, 30);
        $old = now()->subDays(60)->getTimestamp();
        $fresh = now()->subDays(2)->getTimestamp();

        $this->memory->addDocuments(new ConstantEmbeddingsProvider()->embedDocuments([
            $this->document('old-conversation', 'conversation', $old),
            $this->document('fresh-conversation', 'conversation', $fresh),
            $this->document('old-outcome', 'ledger', $old),
            $this->document('old-saved-memory', 'memory', $old),
        ]));

        $this->artisan('agents:prune-memory', ['--app' => $this->kanvasApp->getId()])->assertSuccessful();

        $left = array_map(
            static fn (Document $document): string => (string) $document->getId(),
            SharedCompanyMemory::all($this->memory)
        );

        $this->assertEqualsCanonicalizing(['fresh-conversation', 'old-saved-memory'], $left);
    }

    private function document(string $id, string $kind, int $createdAt): Document
    {
        return SharedCompanyMemory::document("memory {$id}", $kind, $this->kanvasApp->getId(), 1, $createdAt)->setId($id);
    }
}
