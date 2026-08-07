<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Baka\Search\SearchEngineResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\IndexAgentKnowledgeAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentKnowledgeDocument;
use Kanvas\Intelligence\Agents\Services\AgentTypesenseKnowledgeService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Embeddings;
use Tests\TestCase;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

class IndexAgentKnowledgeActionTest extends TestCase
{
    use DatabaseTransactions;

    private const string LIVE_TEST_COLLECTION = 'test_index_agent_knowledge_action';

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set('typesense_search_settings', $this->liveTypesenseSettings());
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value, self::LIVE_TEST_COLLECTION);
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value, 4);

        // Every embedding call returns the same fixed vector — this suite tests
        // chunking/storage plumbing, not real semantic similarity.
        Embeddings::fake(fn () => [[0.1, 0.2, 0.3, 0.4]]);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value);
        $this->kanvasApp->del(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value);
        $this->kanvasApp->del('typesense_search_settings');

        $this->deleteLiveTestCollectionIfExists();

        parent::tearDown();
    }

    public function testIndexesShortContentAsASingleChunkAndItBecomesSearchable(): void
    {
        $chunks = new IndexAgentKnowledgeAction(new AgentKnowledgeDocument(
            app: $this->kanvasApp,
            content: 'Our refund policy allows returns within 30 days.',
            sourceType: 'policy',
            sourceName: 'refund_policy',
        ))->execute();

        $this->assertSame(1, $chunks);

        $results = new AgentTypesenseKnowledgeService($this->kanvasApp)
            ->search([0.1, 0.2, 0.3, 0.4], 1);

        $this->assertNotEmpty($results, 'Expected the ingested chunk to come back from a real search.');
        $this->assertSame('Our refund policy allows returns within 30 days.', $results[0]['content']);
        $this->assertSame('policy', $results[0]['sourceType']);
        $this->assertSame('refund_policy', $results[0]['sourceName']);
    }

    public function testSplitsLongContentIntoMultipleChunks(): void
    {
        $paragraphA = str_repeat('A', 800);
        $paragraphB = str_repeat('B', 800);
        $content = "{$paragraphA}\n\n{$paragraphB}";

        $chunks = new IndexAgentKnowledgeAction(new AgentKnowledgeDocument(
            app: $this->kanvasApp,
            content: $content,
            sourceType: 'policy',
            sourceName: 'long_policy',
        ))->execute();

        $this->assertSame(2, $chunks, 'Two 800-char paragraphs should not both fit in one 1000-char chunk.');
    }

    public function testEmptyContentIndexesNothing(): void
    {
        $chunks = new IndexAgentKnowledgeAction(new AgentKnowledgeDocument(
            app: $this->kanvasApp,
            content: "   \n\n  ",
            sourceType: 'policy',
            sourceName: 'empty_policy',
        ))->execute();

        $this->assertSame(0, $chunks);
    }

    public function testReindexingReplacesOldChunksInsteadOfDuplicating(): void
    {
        $document = fn (string $content) => new AgentKnowledgeDocument(
            app: $this->kanvasApp,
            content: $content,
            sourceType: 'policy',
            sourceName: 'changing_policy',
        );

        // Three paragraphs sized so no two can merge under the 1000-char cap —
        // guarantees 3 separate chunks (ids ...:0, ...:1, ...:2).
        $firstChunks = new IndexAgentKnowledgeAction($document(
            str_repeat('A', 900) . "\n\n" . str_repeat('B', 900) . "\n\n" . str_repeat('C', 900),
        ))->execute();
        $this->assertSame(3, $firstChunks);

        $secondChunks = new IndexAgentKnowledgeAction($document('Just one short paragraph now.'))->execute();
        $this->assertSame(1, $secondChunks);

        $client = SearchEngineResolver::getTypesenseClient($this->liveTypesenseSettings());
        $collection = new AgentTypesenseKnowledgeService($this->kanvasApp)->collection();

        $oldChunkStillExists = true;

        try {
            $client->collections[$collection]->documents['policy:changing_policy:1']->retrieve();
        } catch (ObjectNotFound) {
            $oldChunkStillExists = false;
        }

        $this->assertFalse($oldChunkStillExists, 'Expected reindexing to delete the old chunks before adding the new one.');
    }

    /**
     * @return array{typesense_api_key: string, typesense_nodes: array<int, array<string, mixed>>}
     */
    private function liveTypesenseSettings(): array
    {
        return [
            'typesense_api_key' => 'xyz',
            'typesense_nodes' => [[
                'host' => 'typesense',
                'port' => 8108,
                'path' => '/',
                'protocol' => 'http',
            ]],
        ];
    }

    private function deleteLiveTestCollectionIfExists(): void
    {
        try {
            SearchEngineResolver::getTypesenseClient($this->liveTypesenseSettings())
                ->collections[self::LIVE_TEST_COLLECTION]
                ->delete();
        } catch (Throwable) {
            // Never created, or already cleaned up — fine either way.
        }
    }
}
