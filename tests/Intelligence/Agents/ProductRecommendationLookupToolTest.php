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
 * agent-facing contract only — that the sentence reaches the action unmodified,
 * that the payload shape survives, and that misuse produces a message the model
 * can act on instead of an exception in the chat.
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

    public function testPassesTheSentenceThroughWithoutPreExtraction(): void
    {
        $this->makeProduct('Perfume floral');

        // The whole sentence goes to the search — a budget in it becomes a real
        // filter, which is exactly what pre-extracting in the prompt destroyed.
        $decoded = json_decode($this->invokeTool('un perfume para mi mamá, menos de $500'), true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
    }

    public function testEmptyQueryReturnsAnActionableMessageNotAnException(): void
    {
        $result = $this->invokeTool('   ');

        $this->assertStringContainsString('query', $result);
        $this->assertNull(json_decode($result, true), 'A guidance message, not a payload the model would try to parse.');
    }

    public function testNoMatchTellsTheModelWhatToDoNext(): void
    {
        $result = $this->invokeTool('zzzzznotacatalogword');

        // A bare "no results" reads as "try again" and the model re-calls with
        // the same words until its run budget trips.
        $this->assertStringContainsString('No products found', $result);
        $this->assertStringContainsString('broader', $result);
    }

    public function testRespectsTheLimit(): void
    {
        $this->makeProduct('Reloj uno');
        $this->makeProduct('Reloj dos');
        $this->makeProduct('Reloj tres');

        $decoded = json_decode($this->invokeTool('reloj', 2), true);

        $this->assertCount(2, $decoded);
    }

    public function testExposesOnlyQueryAndLimitToTheModel(): void
    {
        $schema = new ProductRecommendationLookupTool()->schema(new JsonSchemaTypeFactory());

        // Every extra knob is a chance for the model to pre-parse the request
        // and degrade the match; the sentence is the whole input.
        $this->assertSame(['query', 'limit'], array_keys($schema));
    }

    private function invokeTool(string $query, ?int $limit = null): string
    {
        /** @var Users $user */
        $user = auth()->user();

        $tool = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $user->getCurrentCompany());

        $payload = ['query' => $query];
        if ($limit !== null) {
            $payload['limit'] = $limit;
        }

        return (string) $tool->handle(new Request($payload));
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
