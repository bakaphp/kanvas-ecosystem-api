<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Tests\TestCase;

/**
 * Pins the flat discovery fields `toSearchableArray()` carries into the index.
 *
 * They are what the retrieval layer embeds and filters on: a nested
 * `custom_fields.*` path can be neither a Typesense `embed.from` source nor a
 * scalar `filter_by` target, so these have to stay top-level and typed.
 */
class ProductSearchableDiscoveryFieldsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testEmitsTheDiscoveryFieldsWithIndexableTypes(): void
    {
        $indexed = $this->makeProduct()->toSearchableArray();

        $this->assertArrayHasKey('search_blurb', $indexed);
        $this->assertArrayHasKey('price', $indexed);
        $this->assertArrayHasKey('in_stock', $indexed);

        $this->assertIsString($indexed['search_blurb']);
        $this->assertIsBool($indexed['in_stock']);
    }

    public function testBlurbComesFromTheEnrichmentCustomField(): void
    {
        $product = $this->makeProduct();

        $this->assertSame('', $product->toSearchableArray()['search_blurb'], 'An unenriched product indexes an empty blurb, never null.');

        $product->set(SearchFieldEnum::BLURB->value, 'ideal para: personas creativas; ocasiones: cumpleaños');

        $this->assertSame(
            'ideal para: personas creativas; ocasiones: cumpleaños',
            $product->toSearchableArray()['search_blurb'],
        );
    }

    public function testUnpricedProductIndexesZeroPriceAndFalseStockWithoutThrowing(): void
    {
        // getPriceInfoFromDefaultChannel() throws when the company has no default
        // channel or the variant has no channel row. Indexing must degrade to
        // "price unknown" instead of aborting the whole reindex on one product.
        $indexed = $this->makeProduct()->toSearchableArray();

        // 0.0, not null: the index types this as float, and a shopper's
        // "under $50" should still surface an unpriced product — the caller
        // flags it unavailable rather than hiding it.
        $this->assertSame(0.0, $indexed['price']);
        $this->assertFalse($indexed['in_stock']);
    }

    public function testLowestChannelPriceIsNullWhenNothingIsPriced(): void
    {
        $this->assertNull($this->makeProduct()->lowestChannelPrice());
    }

    private function makeProduct(): Products
    {
        $company = Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product->load(['variants', 'categories']);
    }
}
