<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

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
    use ManagesAppSettings;

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

    public function testEmitsThePriceRangeAndTheDiscoverySchemaVersion(): void
    {
        $indexed = $this->makeProduct()->toSearchableArray();

        $this->assertSame(0.0, $indexed['price_min'], 'Unpriced indexes 0 on both ends — the unknown-price sentinel.');
        $this->assertSame(0.0, $indexed['price_max']);
        $this->assertSame(Products::DISCOVERY_SCHEMA_VERSION, $indexed['discovery_schema_version'], 'verify-discovery-index counts records by this.');
    }

    #[Group('serial')]
    public function testIndexesConfiguredNumericAttributesAsAMinMaxRangeInTheCanonicalUnit(): void
    {
        $this->configureMileage();

        $product = $this->makeProduct();
        $first = $product->variants()->firstOrFail();
        $second = Variants::factory()->withProductId($product->getId())->create(['is_deleted' => 0]);
        $first->addAttributes(auth()->user(), [['name' => 'Mileage', 'value' => '62,000 mi']]);
        $second->addAttributes(auth()->user(), [['name' => 'Mileage', 'value' => '80467 km']]);

        $indexed = $product->fresh()->toSearchableArray();

        $this->assertSame(50000.0, round($indexed['num_mileage_min']), '80,467 km is 50,000 mi.');
        $this->assertSame(62000.0, $indexed['num_mileage_max']);
    }

    #[Group('serial')]
    public function testAProductWithNoParseableValueGetsNoNumericFieldNotAZero(): void
    {
        $this->configureMileage();

        $product = $this->makeProduct();
        $product->addAttributes(auth()->user(), [['name' => 'Mileage', 'value' => 'low miles']]);

        $this->assertArrayNotHasKey('num_mileage_min', $product->fresh()->toSearchableArray(), 'A 0 would pass every upper bound.');
    }

    private function configureMileage(): void
    {
        $this->setAppSettings([
            ConfigurationEnum::NUMERIC_ATTRIBUTES->value => ['mileage' => ['attribute' => 'Mileage', 'unit' => 'mi']],
        ]);
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
