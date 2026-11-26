<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Scout\Builder;
use Mockery;
use NeuronAI\Tools\TrackByInputs;
use Override;
use RuntimeException;
use Tests\TestCase;
use Throwable;

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

    public function testDescriptionRequiresTheCustomersVerbatimNaturalLanguageRequest(): void
    {
        $description = (new InventorySearchTool())->getDescription();

        $this->assertStringContainsString('complete inventory request verbatim', $description);
        $this->assertStringContainsString('Never paraphrase', $description);
        $this->assertStringContainsString('inferred make, model, body style, or product name', $description);
        $this->assertStringContainsString('Preserve every stated preference and constraint', $description);
    }

    public function testRunBudgetIsTrackedByInputs(): void
    {
        $tool = new InventorySearchTool();

        $this->assertContains(TrackByInputs::class, class_uses_recursive($tool));

        $tool->setInputs(['product_name' => 'BMW 760i']);
        $firstKey = $tool->getRunKey();

        $tool->setInputs(['product_name' => 'BMW X7']);
        $secondKey = $tool->getRunKey();

        $this->assertNotSame($firstKey, $secondKey);

        $tool->setInputs(['product_name' => 'BMW 760i']);

        $this->assertSame($firstKey, $tool->getRunKey());
    }

    public function testFallsBackToTheNestedCompanyFilterOnCollectionsWithoutCompaniesId(): void
    {
        $user = auth()->user();
        $tool = new ScriptedInventorySearchTool([
            new RuntimeException('Could not find a filter field named `companies_id` in the schema.'),
            new EloquentCollection(),
        ]);
        $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $result = $tool('BMW 760i');

        $this->assertSame('no_matches', $result['status']);
        $this->assertSame(['companies_id', 'company.id'], array_column($tool->calls, 'tenantField'));
    }

    public function testSchemaFallbacksCombineAndApplyOnce(): void
    {
        $user = auth()->user();
        $tool = new ScriptedInventorySearchTool([
            new RuntimeException('Could not find a filter field named `companies_id` in the schema.'),
            new RuntimeException('Could not find a field named `translations.name` in the schema.'),
            new RuntimeException('Could not find a filter field named `company.id` in the schema.'),
        ]);
        $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $result = $tool('BMW 760i');

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            [
                ['companies_id', 'name,description,translations.name,translations.description'],
                ['company.id', 'name,description,translations.name,translations.description'],
                ['company.id', 'name,description'],
            ],
            array_map(fn (array $call) => [$call['tenantField'], $call['queryBy']], $tool->calls),
        );
    }

    public function testSearchFailureIsSpeakableAndHidesEngineInternals(): void
    {
        $user = auth()->user();
        $tool = new ScriptedInventorySearchTool([
            new RuntimeException('Typesense 404: Could not find a filter field named `apps_id` in the schema.'),
        ]);
        $tool->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $result = $tool('BMW 760i');

        $this->assertSame('error', $result['status']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringNotContainsString('apps_id', $result['message']);
        $this->assertStringNotContainsString('Typesense', $result['message']);
        $this->assertCount(1, $tool->calls, 'an unknown failure is not retried');
    }

    public function testDropsProductsOfAnotherCompany(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $foreign = new Products();
        $foreign->forceFill(['id' => 1, 'companies_id' => $company->getId() + 1]);
        $tool = new ScriptedInventorySearchTool([new EloquentCollection([$foreign])]);
        $tool->withContext(app(Apps::class), $company, $user);

        $result = $tool('BMW 760i');

        $this->assertSame('no_matches', $result['status']);
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

/**
 * Answers each search with the next scripted outcome — an exception the engine would throw, or
 * the rows it would return — and records the fields each attempt used.
 */
final class ScriptedInventorySearchTool extends InventorySearchTool
{
    /** @var list<array{queryBy: string, tenantField: string}> */
    public array $calls = [];

    /**
     * @param list<Throwable|EloquentCollection> $outcomes
     */
    public function __construct(private array $outcomes)
    {
    }

    #[Override]
    protected function searchQuery(
        string $productName,
        string $queryBy = 'name,description,translations.name,translations.description',
        string $tenantField = 'companies_id',
    ): Builder {
        $this->calls[] = ['queryBy' => $queryBy, 'tenantField' => $tenantField];
        $outcome = array_shift($this->outcomes);

        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('take')->andReturnSelf();
        if ($outcome instanceof Throwable) {
            $builder->shouldReceive('get')->andThrow($outcome);
        } else {
            $builder->shouldReceive('get')->andReturn($outcome);
        }

        return $builder;
    }
}
