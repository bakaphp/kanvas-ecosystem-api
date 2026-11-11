<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ProductRecommendationLookupTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class NeuronProductRecommendationLookupToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function testReturnsProductsWithTheirVariants(): void
    {
        $product = $this->makeProduct('Reloj de lujo');

        $result = $this->tool()(request: 'un reloj elegante', search_query: 'reloj');

        $this->assertSame($product->getId(), $result[0]['id']);
        $this->assertSame(['id', 'name', 'slug', 'url', 'is_published', 'is_available', 'total_stock', 'variants'], array_keys($result[0]), 'Same shape as inventory_search, so follow-up actions work unchanged.');
        $this->assertSame(
            $product->variants()->first()->getId(),
            $result[0]['variants'][0]['id'],
            'variants[].id is what variant_detail and order actions take.',
        );
    }

    public function testAnInvalidFilterIsReturnedForTheModelToCorrect(): void
    {
        $result = $this->tool()(request: 'a car', numeric_filters: [['field' => 'odometer', 'max' => 100000]]);

        $this->assertSame('invalid_filter', $result['error']);
        $this->assertSame('odometer', $result['field']);
    }

    public function testNoMatchAnswersLikeInventorySearch(): void
    {
        $result = $this->tool()(request: 'zzzzznotacatalogword');

        $this->assertSame('no_matches', $result['status'], 'The same no_matches answer inventory_search gives, so the RAG handoff rule fires.');
        $this->assertFalse($result['inventory_availability_confirmed']);
        $this->assertStringContainsString('Do not retry', $result['relax']);
    }

    private function tool(): ProductRecommendationLookupTool
    {
        /** @var Users $user */
        $user = auth()->user();

        return new ProductRecommendationLookupTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    private function makeProduct(string $name): Products
    {
        /** @var Users $user */
        $user = auth()->user();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['name' => $name, 'is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
