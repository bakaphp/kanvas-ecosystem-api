<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\TypesenseProductRecommendationTool;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class TypesenseProductRecommendationToolTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $user;
    protected mixed $originalSearchEngine = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;

        $this->originalSearchEngine = $this->kanvasApp->get('search_engine');

        new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany())->run();
    }

    protected function tearDown(): void
    {
        // search_engine lives in Redis-backed app settings, which DatabaseTransactions
        // does NOT roll back — restore it so sibling suites don't resolve Scout to a
        // leaked engine.
        if ($this->originalSearchEngine === null) {
            $this->kanvasApp->del('search_engine');
        } else {
            $this->kanvasApp->set('search_engine', $this->originalSearchEngine);
        }

        parent::tearDown();
    }

    public function testRequiresAQuery(): void
    {
        $result = (string) $this->tool()->handle(new Request(['query' => '   ']));

        $this->assertStringContainsString('Provide the customer request', $result);
    }

    public function testReturnsNotEnabledMessageWhenTenantIsNotOnTypesense(): void
    {
        $this->kanvasApp->del('search_engine');

        $result = (string) $this->tool()->handle(new Request([
            'query' => 'un regalo para mi hermano mayor que le gustan las cosas caras',
        ]));

        $this->assertStringContainsString('not enabled', $result);
    }

    public function testDegradesToNoResultsWhenTypesenseUnreachable(): void
    {
        // Tenant says typesense, but there is no reachable cluster in the test
        // env → the tool must catch and return a clean no-results message, never
        // throw into the agent loop.
        $this->kanvasApp->set('search_engine', 'typesense');

        $result = (string) $this->tool()->handle(new Request([
            'query' => 'un regalo para mi hermano mayor que le gustan las cosas caras',
        ]));

        $this->assertStringContainsString('No products found', $result);
    }

    private function tool(): TypesenseProductRecommendationTool
    {
        return new TypesenseProductRecommendationTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany());
    }
}
