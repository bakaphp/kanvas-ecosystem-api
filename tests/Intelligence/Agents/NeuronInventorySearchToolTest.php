<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Laravel\Scout\Builder;
use Mockery;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

final class NeuronInventorySearchToolTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(Apps::class);

        parent::tearDown();
    }

    public function testAddsQueryByOnlyForTypesense(): void
    {
        $this->forceSearchEngine('typesense');

        $query = $this->tool()->exposeSearchQuery('BMW 760i');

        $this->assertSame(
            'name,description,translations.name,translations.description',
            $query->options['query_by'] ?? null,
        );
    }

    public function testDoesNotAddQueryByForOtherSearchEngines(): void
    {
        $this->forceSearchEngine('algolia');

        $query = $this->tool()->exposeSearchQuery('BMW 760i');

        $this->assertArrayNotHasKey('query_by', $query->options);
    }

    public function testSearchIsExplicitlyScopedToContextCompanyForTypesense(): void
    {
        $this->assertSearchUsesContextCompany('typesense');
    }

    public function testSearchIsExplicitlyScopedToContextCompanyForAlgolia(): void
    {
        $this->assertSearchUsesContextCompany('algolia');
    }

    public function testInvokeFailsClosedWithoutTenantContext(): void
    {
        $result = (new InventorySearchTool())('BMW 760i');

        $this->assertSame('no_tenant_context', $result['reason']);
    }

    public function testNoMatchesResponseDirectsAgentToRagBusinessRule(): void
    {
        $tool = $this->tool();
        $result = $tool->exposeNoMatchesResponse('2024 Mercedes-Benz S-Class');

        $this->assertStringContainsString('no_matches', $tool->getDescription());
        $this->assertSame('no_matches', $result['status']);
        $this->assertSame('no_matching_inventory', $result['reason']);
        $this->assertFalse($result['inventory_availability_confirmed']);
        $this->assertStringContainsString(
            'No Matching Inventory Results — STRICT HANDOFF RULE',
            $result['instruction'],
        );
    }

    public function testRunBudgetIsTrackedByInputs(): void
    {
        $tool = new InventorySearchTool();

        $this->assertInstanceOf(HasRunKey::class, $tool);

        $tool->setInputs(['product_name' => 'BMW 760i']);
        $firstKey = $tool->getRunKey();

        $tool->setInputs(['product_name' => 'BMW X7']);
        $secondKey = $tool->getRunKey();

        $this->assertNotSame($firstKey, $secondKey);

        $tool->setInputs(['product_name' => 'BMW 760i']);

        $this->assertSame($firstKey, $tool->getRunKey());
    }

    private function tool(): TestableInventorySearchTool
    {
        return new TestableInventorySearchTool();
    }

    private function forceSearchEngine(string $engine): void
    {
        $realApp = app(Apps::class);
        $app = Mockery::mock($realApp)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(
            fn (string $name, mixed $default = null): mixed => str_ends_with($name, 'search_engine')
                ? $engine
                : $realApp->get($name, $default),
        );

        app()->instance(Apps::class, $app);
    }

    private function assertSearchUsesContextCompany(string $engine): void
    {
        $this->forceSearchEngine($engine);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $tool = $this->tool()->withContext(app(Apps::class), $company, $user);

        $query = $tool->exposeSearchQuery('BMW 760i');
        $companyFilters = collect($query->wheres)
            ->where('field', 'companies_id')
            ->where('value', $company->getId());

        $this->assertNotEmpty($companyFilters);
    }
}

final class TestableInventorySearchTool extends InventorySearchTool
{
    public function exposeSearchQuery(string $productName): Builder
    {
        return $this->searchQuery($productName);
    }

    public function exposeNoMatchesResponse(string $productName): array
    {
        return $this->noMatchesResponse($productName);
    }
}
