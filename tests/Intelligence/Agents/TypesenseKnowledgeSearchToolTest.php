<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Knowledge\TypesenseKnowledgeSearchTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
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

        // Avoid real embedding calls — the tool only needs *an* embedding vector,
        // not a real one, to exercise the Typesense search path.
        Embeddings::fake();
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(KnowledgeConfigurationEnum::COLLECTION->value);
        $this->kanvasApp->del(ConfigurationEnum::GEMINI_KEY->value);

        parent::tearDown();
    }

    public function testRequiresAQuery(): void
    {
        $result = (string) $this->tool()->handle(new Request(['query' => '   ']));

        $this->assertStringContainsString('Provide a `query`', $result);
    }

    public function testDegradesGracefullyWhenNoKnowledgeIsAvailable(): void
    {
        // Whether Typesense is unreachable or simply has no collection/rows for this
        // tenant yet, the tool must return a clean message and never throw into the
        // agent loop (same contract as ProductRecommendationLookupTool).
        $result = (string) $this->tool()->handle(new Request(['query' => 'refund policy']));

        $this->assertTrue(
            str_contains($result, 'not reachable') || str_contains($result, 'No relevant knowledge found'),
            "Expected a graceful degradation message, got: {$result}",
        );
    }

    public function testRegistersAPerAppEmbeddingProvider(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::GEMINI_KEY->value, 'test-gemini-key');

        // The embedder registers its per-app provider before the search runs, so the
        // registration is observable even though the unreachable search then degrades.
        $this->tool($this->agent())->handle(new Request(['query' => 'refund policy']));

        $name = "knowledge_gemini_app_{$this->kanvasApp->getId()}";

        $this->assertSame('test-gemini-key', config("ai.providers.{$name}.key"));
    }

    private function tool(?Agent $agent = null): TypesenseKnowledgeSearchTool
    {
        return new TypesenseKnowledgeSearchTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany(), $agent);
    }

    private function agent(): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create(['provider' => 'laravel', 'handler' => KanvasGenericLaravelAgent::class]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->user->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $this->user->getId(),
            ]);
    }
}
