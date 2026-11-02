<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ProductRecommendationLookupTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * The tool is a pass-through to RecommendProductsAction, so this covers the
 * agent-facing contract only — the structured arguments, the payload shape, and
 * that misuse produces a correctable error instead of an exception in the chat.
 *
 * Matching, budgets, tenant scoping and engine selection are the action's
 * behavior and are tested in tests/Inventory/Recommendations/.
 */
class ProductRecommendationLookupToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        Cache::flush();
    }

    public function testReturnsTheRecommendationPayloadShape(): void
    {
        $product = $this->makeProduct('Reloj de lujo');

        $decoded = json_decode($this->invokeTool('reloj'), true);

        $this->assertIsArray($decoded);
        $this->assertSame($product->getId(), $decoded[0]['product']['id']);
        $this->assertArrayHasKey('variants', $decoded[0]);
        $this->assertArrayHasKey('channel', $decoded[0]['variants'][0]);
        $this->assertArrayHasKey('is_available', $decoded[0]['variants'][0]['channel']);
    }

    public function testStillParsesABudgetOutOfTheVerbatimRequest(): void
    {
        $this->makeProduct('Perfume floral');

        $decoded = json_decode($this->invokeTool('un perfume para mi mamá, menos de $500'), true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
    }

    public function testEmptyQueryReturnsAnActionableMessageNotAnException(): void
    {
        $result = $this->invokeTool('   ');

        $this->assertSame('request', json_decode($result, true)['field'] ?? null, 'A correctable error naming the missing argument, not an exception.');
    }

    public function testNoMatchTellsTheModelWhatToDoNext(): void
    {
        $result = $this->invokeTool('zzzzznotacatalogword');

        // A bare "no results" reads as "try again" and the model re-calls with
        // the same words until its run budget trips.
        $this->assertStringContainsString('No products match', $result);
        $this->assertStringContainsString('relax one limit', $result);
    }

    public function testRespectsTheLimit(): void
    {
        $this->makeProduct('Reloj uno');
        $this->makeProduct('Reloj dos');
        $this->makeProduct('Reloj tres');

        $decoded = json_decode($this->invokeTool('reloj', 2), true);

        $this->assertCount(2, $decoded);
    }

    public function testExposesTheStructuredContractToTheModel(): void
    {
        $schema = new ProductRecommendationLookupTool()->schema(new JsonSchemaTypeFactory());

        $this->assertSame(
            ['request', 'search_query', 'preferences', 'min_price', 'max_price', 'category', 'audience', 'numeric_filters', 'alternative_queries', 'limit'],
            array_keys($schema),
        );
    }

    public function testAnInvalidFilterComesBackAsACorrectableErrorNotResults(): void
    {
        $result = json_decode($this->invokeToolWith(['request' => 'a car', 'min_price' => 40000, 'max_price' => 30000]), true);

        $this->assertSame('invalid_filter', $result['error'] ?? null);
        $this->assertSame('price', $result['field']);
    }

    public function testAnExplicitBudgetFromTheAgentIsAccepted(): void
    {
        $this->makeProduct('Reloj de lujo');

        // Enforcement itself is covered in RecommendProductsActionTest; this pins that the
        // tool accepts the argument and still answers with the structured payload.
        $decoded = json_decode($this->invokeToolWith(['request' => 'un reloj', 'search_query' => 'reloj', 'max_price' => 500]), true);

        $this->assertIsArray($decoded);
    }

    private function invokeToolWith(array $payload): string
    {
        /** @var Users $user */
        $user = auth()->user();

        return (string) new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $user->getCurrentCompany())
            ->handle(new Request($payload));
    }

    private function invokeTool(string $query, ?int $limit = null): string
    {
        $payload = ['request' => $query];
        if ($limit !== null) {
            $payload['limit'] = $limit;
        }

        return $this->invokeToolWith($payload);
    }

    private function makeProduct(string $name): Products
    {
        /** @var Users $user */
        $user = auth()->user();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['name' => $name, 'is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
