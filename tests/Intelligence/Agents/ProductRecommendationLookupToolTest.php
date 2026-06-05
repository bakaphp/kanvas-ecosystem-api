<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\ProductRecommendationLookupTool;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ProductRecommendationLookupToolTest extends TestCase
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

        new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany())->run();
    }

    public function testTokenizesMultiWordKeyword(): void
    {
        // A single contiguous LIKE on "%perfume hombre%" would never match this
        // name — tokenization is what makes the multi-word interest hit.
        $product = $this->createProduct('Perfume Hugo Boss para Hombre ' . uniqid());

        $ids = $this->ids($this->lookup(['keyword' => 'perfume hombre']));

        $this->assertContains($product->getId(), $ids);
    }

    public function testRanksProductMatchingMoreTermsHigher(): void
    {
        $more = $this->createProduct('Perfume para Hombre ' . uniqid());
        $less = $this->createProduct('Perfume Floral ' . uniqid());

        $ids = $this->ids($this->lookup(['keyword' => 'perfume hombre']));

        $this->assertContains($more->getId(), $ids);
        $this->assertContains($less->getId(), $ids);
        $this->assertLessThan(
            array_search($less->getId(), $ids, true),
            array_search($more->getId(), $ids, true),
            'Product matching both terms should rank above the one matching only "perfume".',
        );
    }

    public function testRecipientGenderBoostsMatchingProduct(): void
    {
        $male = $this->createProduct('Reloj Plateado ' . uniqid(), 'ideal para hombre, estilo masculino');
        $neutral = $this->createProduct('Reloj Dorado ' . uniqid(), 'elegante y brillante');

        $ids = $this->ids($this->lookup(['keyword' => 'reloj', 'recipient_gender' => 'male']));

        $this->assertContains($male->getId(), $ids);
        $this->assertContains($neutral->getId(), $ids);
        $this->assertLessThan(
            array_search($neutral->getId(), $ids, true),
            array_search($male->getId(), $ids, true),
            'recipient_gender=male should rank the male-targeted product higher.',
        );
    }

    public function testEmptyKeywordReturnsTopRatedFirst(): void
    {
        $high = $this->createProduct('Z Top Rated Item ' . uniqid(), rating: 5.0);
        $low = $this->createProduct('Z Low Rated Item ' . uniqid(), rating: 1.0);

        $ids = $this->ids($this->lookup([]));

        $this->assertContains($high->getId(), $ids);
        $this->assertContains($low->getId(), $ids);
        $this->assertLessThan(
            array_search($low->getId(), $ids, true),
            array_search($high->getId(), $ids, true),
            'With no keyword, higher-rated products should come first.',
        );
    }

    public function testCategoryParamMatchesProductWithoutKeywordInName(): void
    {
        $company = $this->user->getCurrentCompany();
        $categoryName = 'GiftIdeas' . uniqid();

        $category = Categories::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->user->getId(),
            'name' => $categoryName,
            'slug' => strtolower($categoryName),
        ]);

        $product = $this->createProduct('Nondescript Item ' . uniqid(), categoryIds: [$category->getId()]);

        $ids = $this->ids($this->lookup(['categories' => [$categoryName]]));

        $this->assertContains($product->getId(), $ids);
    }

    public function testInStockFilterUsesTotalQuantityNotDefaultChannelLink(): void
    {
        // Stock lives on the warehouse total, not necessarily on the default
        // channel's productVariantWarehouse link. With only_in_stock=true the
        // product must still surface when getTotalQuantity() > 0.
        $keyword = 'Cuchillotest' . uniqid();
        $product = $this->createProduct($keyword . ' Chef');

        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $variant->set('total_variant_quantity', 7);

        $result = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request(['keyword' => $keyword, 'only_in_stock' => true, 'limit' => 20]));

        $data = json_decode((string) $result, true);
        $this->assertIsArray($data, 'Expected products, got: ' . $result);

        $match = collect($data)->firstWhere('product.id', $product->getId());
        $this->assertNotNull($match, 'In-stock product was wrongly filtered out.');
        $this->assertSame(7, $match['variants'][0]['channel']['quantity']);
    }

    public function testUnpricedProductIsShownAndFlaggedUnavailableEvenWithBudget(): void
    {
        $keyword = 'Unpricedgift' . uniqid();
        $product = $this->createProduct($keyword . ' Box');

        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $variant->set('total_variant_quantity', 4);

        // Budget set, but the product has no channel price — it must still show.
        $result = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request([
                'keyword' => $keyword,
                'only_in_stock' => true,
                'max_price' => 50,
                'limit' => 20,
            ]));

        $data = json_decode((string) $result, true);
        $this->assertIsArray($data, 'Expected products, got: ' . $result);

        $match = collect($data)->firstWhere('product.id', $product->getId());
        $this->assertNotNull($match, 'Unpriced product was wrongly filtered out by the budget.');

        $channel = $match['variants'][0]['channel'];
        $this->assertNull($channel['price']);
        $this->assertFalse($channel['is_available'], 'Unpriced variant should be flagged unavailable.');
    }

    public function testOutOfStockProductIsStillReturnedFlaggedWhenOnlyInStockTrue(): void
    {
        // only_in_stock is a soft preference now — a zero-stock match must still
        // come back (flagged is_available=false), not be hard-filtered out.
        $keyword = 'Outofstockgift' . uniqid();
        $product = $this->createProduct($keyword . ' Box');

        $variant = $product->variants->first();
        $this->assertNotNull($variant);
        $variant->set('total_variant_quantity', 0);

        $result = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request(['keyword' => $keyword, 'only_in_stock' => true, 'limit' => 20]));

        $data = json_decode((string) $result, true);
        $this->assertIsArray($data, 'Expected products, got: ' . $result);

        $match = collect($data)->firstWhere('product.id', $product->getId());
        $this->assertNotNull($match, 'Out-of-stock match should still be returned (soft preference).');

        $channel = $match['variants'][0]['channel'];
        $this->assertSame(0, $channel['quantity']);
        $this->assertFalse($channel['is_available']);
    }

    public function testSearchEngineConfiguredReflectsTenantEngineSetting(): void
    {
        $tool = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany());

        $method = new \ReflectionMethod($tool, 'searchEngineConfigured');

        $this->kanvasApp->set('search_engine', 'algolia');
        $this->assertTrue($method->invoke($tool), 'A real engine should enable the Scout path.');

        $this->kanvasApp->set('search_engine', 'null');
        $this->assertFalse($method->invoke($tool), 'null driver must fall back to SQL.');

        $this->kanvasApp->set('search_engine', 'database');
        $this->assertFalse($method->invoke($tool), 'database driver is not a real search engine here.');
    }

    public function testFallsBackToSqlWhenEngineUnreachable(): void
    {
        // Engine "configured" but unreachable (no creds) → must degrade to the
        // SQL path and still return matches, never blow up.
        $this->kanvasApp->set('search_engine', 'algolia');

        $keyword = 'Fallbackgift' . uniqid();
        $product = $this->createProduct($keyword . ' Item');

        $ids = $this->ids($this->lookup(['keyword' => $keyword]));

        $this->assertContains($product->getId(), $ids);
    }

    public function testReturnsRecommendationShape(): void
    {
        $product = $this->createProduct('Cafetera Premium ' . uniqid());

        $data = $this->lookup(['keyword' => 'cafetera']);

        $match = collect($data)->firstWhere('product.id', $product->getId());

        $this->assertNotNull($match);
        $this->assertArrayHasKey('product', $match);
        $this->assertArrayHasKey('variants', $match);
        $this->assertArrayHasKey('slug', $match['product']);
        $this->assertNotEmpty($match['variants']);
    }

    /**
     * @param array<int, int> $categoryIds
     */
    private function createProduct(
        string $name,
        ?string $description = null,
        array $categoryIds = [],
        float $rating = 0.0,
    ): Products {
        $product = new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $this->user->getCurrentCompany(),
                user: $this->user,
                name: $name,
                description: $description,
                is_published: true,
                sku: 'REC-' . uniqid(),
                categories: array_map(fn (int $id) => ['id' => $id], $categoryIds),
            ),
            $this->user,
        )->execute();

        if ($rating > 0) {
            $product->rating = $rating;
            $product->saveQuietly();
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function lookup(array $args): array
    {
        $result = new ProductRecommendationLookupTool()
            ->withContext($this->kanvasApp, $this->user->getCurrentCompany())
            ->handle(new Request($args + ['only_in_stock' => false, 'limit' => 20]));

        $decoded = json_decode((string) $result, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $data
     *
     * @return array<int, int>
     */
    private function ids(array $data): array
    {
        return array_map(fn (array $r) => (int) $r['product']['id'], $data);
    }
}
