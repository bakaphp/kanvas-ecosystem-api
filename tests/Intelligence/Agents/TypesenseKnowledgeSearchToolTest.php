<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\Knowledge\TypesenseKnowledgeSearchTool;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class TypesenseKnowledgeSearchToolTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;

        // Avoid real OpenAI calls — the tool only needs *an* embedding vector,
        // not a real one, to exercise the Typesense search path.
        Embeddings::fake();
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::TYPESENSE_VECTOR_COLLECTION->value);
        $this->kanvasApp->del(ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY->value);

        parent::tearDown();
    }

    public function testRequiresAQuery(): void
    {
        $result = (string) $this->tool()->handle(new Request(['query' => '   ']));

        $this->assertStringContainsString('Provide a `query`', $result);
    }

    public function testDegradesGracefullyWhenTypesenseUnreachable(): void
    {
        // No reachable Typesense cluster in the test environment — the tool
        // must catch that and return a clean message, never throw into the
        // agent loop (same contract as TypesenseProductRecommendationTool).
        $result = (string) $this->tool()->handle(new Request(['query' => 'refund policy']));

        $this->assertStringContainsString('not reachable', $result);
    }

    public function testRegistersAPerAppEmbeddingProvider(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::OPEN_AI_EMBEDDINGS_KEY->value, 'sk-test-key');

        $this->tool()->handle(new Request(['query' => 'refund policy']));

        $name = "openai_agent_knowledge_app_{$this->kanvasApp->getId()}";

        $this->assertSame('sk-test-key', config("ai.providers.{$name}.key"));
    }

    private function tool(): TypesenseKnowledgeSearchTool
    {
        return new TypesenseKnowledgeSearchTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany());
    }
}
