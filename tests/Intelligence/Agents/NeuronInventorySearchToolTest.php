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
}

final class TestableInventorySearchTool extends InventorySearchTool
{
    public function exposeSearchQuery(string $productName): Builder
    {
        return $this->searchQuery($productName);
    }
}
