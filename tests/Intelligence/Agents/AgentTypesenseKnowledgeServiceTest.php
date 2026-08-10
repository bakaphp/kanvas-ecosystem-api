<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Services\AgentTypesenseKnowledgeService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Tests\Intelligence\Traits\InteractsWithLiveTypesense;
use Tests\TestCase;
use Throwable;

class AgentTypesenseKnowledgeServiceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithLiveTypesense;

    private const string LIVE_TEST_COLLECTION = 'test_agent_typesense_knowledge_service';

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    protected function tearDown(): void
    {
        // App settings are Redis-backed and not rolled back by DatabaseTransactions.
        $this->kanvasApp->del(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value);
        $this->kanvasApp->del(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value);
        $this->kanvasApp->del('typesense_search_settings');

        $this->deleteTypesenseTestCollection(self::LIVE_TEST_COLLECTION);

        parent::tearDown();
    }

    public function testCollectionDefaultsToPerAppName(): void
    {
        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $this->assertSame(
            "agent_knowledge_app_{$this->kanvasApp->getId()}",
            $service->collection(),
        );
    }

    public function testCollectionUsesConfiguredOverride(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value, 'shared_knowledge');

        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $this->assertSame('shared_knowledge', $service->collection());
    }

    public function testDimensionDefaultsTo1536(): void
    {
        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $this->assertSame(1536, $service->dimension());
    }

    public function testDimensionUsesConfiguredOverride(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value, 768);

        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $this->assertSame(768, $service->dimension());
    }

    public function testStoreRejectsAnEmbeddingThatDoesNotMatchTheConfiguredDimension(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value, 4);

        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $this->expectException(InvalidArgumentException::class);

        $service->store(
            id: 'mismatch',
            content: 'irrelevant',
            embedding: [0.1, 0.2], // 2 dims, configured for 4 — must be rejected before any network call
            sourceType: 'test',
            sourceName: 'unit-test',
        );
    }

    public function testSearchThrowsWhenPointedAtTheUnconfiguredDefaultHost(): void
    {
        // With no per-app `typesense_search_settings`, getTypesenseClient() falls
        // back to config('scout.typesense.*'), whose default host is 'localhost'.
        // From inside the PHP container that does NOT resolve to the sibling
        // Typesense container (see the real cluster test below, which points at
        // the correct 'typesense' hostname and succeeds) — this proves the
        // service doesn't silently swallow a misconfigured/unreachable client.
        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);

        $threw = false;

        try {
            $service->search(array_fill(0, 1536, 0.0));
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected search() to throw against the unreachable default host.');
    }

    public function testStoreAndSearchRoundTripThroughTheRealTypesenseCluster(): void
    {
        $this->skipIfTypesenseUnreachable();

        $this->kanvasApp->set('typesense_search_settings', $this->liveTypesenseSettings());
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value, self::LIVE_TEST_COLLECTION);
        $this->kanvasApp->set(ConfigurationEnum::TYPESENSE_VECTOR_DIMENSION->value, 4);

        $service = new AgentTypesenseKnowledgeService($this->kanvasApp);
        $embedding = [1.0, 0.0, 0.0, 0.0];

        $service->store(
            id: 'refund-policy',
            content: 'Our refund policy allows returns within 30 days.',
            embedding: $embedding,
            sourceType: 'test',
            sourceName: 'unit-test',
        );

        $results = $service->search($embedding, topK: 1);

        $this->assertNotEmpty($results, 'Expected a hit back from the live Typesense cluster.');
        $this->assertSame('Our refund policy allows returns within 30 days.', $results[0]['content']);
        $this->assertSame('test', $results[0]['sourceType']);
        $this->assertSame('unit-test', $results[0]['sourceName']);
    }

}
