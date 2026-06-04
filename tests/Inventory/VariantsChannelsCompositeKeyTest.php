<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\Actions\CreatePriceHistoryAction;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Models\VariantChannelPriceHistory;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Regression coverage for the composite-key + multi-column relationship
 * plumbing on Kanvas\Inventory\Variants\Models\VariantsChannels.
 *
 * This is the canonical sibling of ProductsCategories — it carries the same
 * HasCompositeKey + Compoships trait conflict resolution (with HasCompositeKey
 * winning on setKeysForSaveQuery) and additionally exercises Compoships's
 * multi-column relationship support via pricesHistory(['product_variants_warehouse_id','channels_id']).
 *
 * The model declares $forceDeleting = true, so delete() is a hard DELETE
 * (not a soft delete) — this still routes through setKeysForSaveQuery via
 * Model::performDeleteOnModel.
 */
class VariantsChannelsCompositeKeyTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Warehouses $warehouse;
    private Channels $channelA;
    private Channels $channelB;
    private VariantsWarehouses $variantWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $company)->run();

        $product = $this->createProduct('PivotVCProduct');
        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $this->warehouse = Warehouses::fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->firstOrFail();
        $this->channelA = Channels::fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->firstOrFail();
        $this->channelB = $this->createChannel('PivotVCChannelB');

        $this->variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $this->warehouse->getId(),
            ],
            [
                'quantity' => 0,
                'price' => 1.00,
                'sku' => $variant->sku ?? 'PivotVCSku-' . uniqid(),
                'position' => 1,
                'is_default' => 1,
            ]
        );
    }

    public function testSaveAndFindRoundTripWithCompositeKey(): void
    {
        $row = $this->makeRow($this->variantWarehouse, $this->channelA, isPublished: true);
        $row->save();

        $found = VariantsChannels::find([
            $this->variantWarehouse->getId(),
            $this->channelA->getId(),
        ]);

        $this->assertNotNull($found, 'Composite-key find() should return the row just saved');
        $this->assertSame($this->variantWarehouse->getId(), (int) $found->product_variants_warehouse_id);
        $this->assertSame($this->channelA->getId(), (int) $found->channels_id);
        $this->assertTrue((bool) $found->is_published);
    }

    public function testUpdateScopesByCompositeKeyOnly(): void
    {
        $rowA = $this->makeRow($this->variantWarehouse, $this->channelA, isPublished: true);
        $rowA->save();

        $rowB = $this->makeRow($this->variantWarehouse, $this->channelB, isPublished: true);
        $rowB->save();

        $rowA->is_published = false;
        $rowA->save();

        $aRefetched = VariantsChannels::find([
            $this->variantWarehouse->getId(),
            $this->channelA->getId(),
        ]);
        $bRefetched = VariantsChannels::find([
            $this->variantWarehouse->getId(),
            $this->channelB->getId(),
        ]);

        $this->assertNotNull($aRefetched);
        $this->assertNotNull($bRefetched);
        $this->assertFalse((bool) $aRefetched->is_published);
        $this->assertTrue(
            (bool) $bRefetched->is_published,
            'Row B must be unchanged — proves the composite-key WHERE on UPDATE matched only row A'
        );
    }

    public function testHardDeleteScopesByCompositeKeyOnly(): void
    {
        $rowA = $this->makeRow($this->variantWarehouse, $this->channelA);
        $rowA->save();

        $rowB = $this->makeRow($this->variantWarehouse, $this->channelB);
        $rowB->save();

        // $forceDeleting = true on VariantsChannels — delete() is a hard DELETE,
        // still routed through setKeysForSaveQuery by Model::performDeleteOnModel.
        $rowA->delete();

        $aRefetched = VariantsChannels::find([
            $this->variantWarehouse->getId(),
            $this->channelA->getId(),
        ]);
        $bRefetched = VariantsChannels::find([
            $this->variantWarehouse->getId(),
            $this->channelB->getId(),
        ]);

        $this->assertNull($aRefetched, 'Row A should be hard-deleted');
        $this->assertNotNull(
            $bRefetched,
            'Row B must survive — proves the composite-key WHERE on DELETE matched only row A'
        );
    }

    public function testComposhipsTraitOverridesAreActive(): void
    {
        // Compoships overrides getAttribute() and qualifyColumn() to also accept
        // array arguments (vanilla Eloquent only accepts a scalar). Hitting both
        // proves the Compoships trait is actually loaded on this model and the
        // `HasCompositeKey::setKeysForSaveQuery insteadof Compoships` conflict
        // resolution didn't accidentally exclude the rest of the trait.
        $row = $this->makeRow($this->variantWarehouse, $this->channelA);
        $row->save();

        $values = $row->getAttribute(['product_variants_warehouse_id', 'channels_id']);
        $this->assertIsArray($values, 'Compoships::getAttribute should accept an array of column names');
        $this->assertSame($this->variantWarehouse->getId(), (int) $values[0]);
        $this->assertSame($this->channelA->getId(), (int) $values[1]);

        $qualified = $row->qualifyColumn(['product_variants_warehouse_id', 'channels_id']);
        $this->assertIsArray($qualified, 'Compoships::qualifyColumn should accept an array of column names');
        $this->assertSame('products_variants_channels.product_variants_warehouse_id', $qualified[0]);
        $this->assertSame('products_variants_channels.channels_id', $qualified[1]);
    }

    public function testPricesHistoryMultiColumnRelationResolves(): void
    {
        // Regression for the pricesHistory() multi-column HasMany on
        // ['product_variants_warehouse_id', 'channels_id']. Until VariantChannelPriceHistory
        // adopted the Compoships trait, calling this relation threw
        // Awobaz\Compoships\Exceptions\InvalidUsageException at relation build time.
        $row = $this->makeRow($this->variantWarehouse, $this->channelA);
        $row->save();

        // Seed two history rows under (variantWarehouse, channelA) and one
        // unrelated history row under channelB, all sharing the same
        // product_variants_warehouse_id — proves the relation matches on the
        // full composite pair, not just the warehouse column.
        new CreatePriceHistoryAction(
            variantsWarehouses: $this->variantWarehouse,
            channel: $this->channelA,
            price: 5.00,
            user: $this->kanvasUser,
        )->execute();
        new CreatePriceHistoryAction(
            variantsWarehouses: $this->variantWarehouse,
            channel: $this->channelA,
            price: 7.50,
            user: $this->kanvasUser,
        )->execute();
        new CreatePriceHistoryAction(
            variantsWarehouses: $this->variantWarehouse,
            channel: $this->channelB,
            price: 99.00,
            user: $this->kanvasUser,
        )->execute();

        $history = $row->pricesHistory()->get();

        $this->assertCount(2, $history, 'Multi-column relation should match only the channelA rows');
        foreach ($history as $entry) {
            $this->assertInstanceOf(VariantChannelPriceHistory::class, $entry);
            $this->assertSame($this->variantWarehouse->getId(), (int) $entry->product_variants_warehouse_id);
            $this->assertSame($this->channelA->getId(), (int) $entry->channels_id);
        }

        $prices = $history->pluck('price')->map(fn ($p) => (float) $p)->sort()->values()->all();
        $this->assertSame([5.0, 7.5], $prices);
    }

    private function makeRow(
        VariantsWarehouses $vw,
        Channels $channel,
        bool $isPublished = false,
    ): VariantsChannels {
        return new VariantsChannels([
            'product_variants_warehouse_id' => $vw->getId(),
            'channels_id' => $channel->getId(),
            'products_variants_id' => $vw->products_variants_id,
            'warehouses_id' => $vw->warehouses_id,
            'price' => 10.00,
            'discounted_price' => 9.00,
            'is_published' => $isPublished,
        ]);
    }

    private function createProduct(string $name): Products
    {
        $company = $this->kanvasUser->getCurrentCompany();

        return new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $this->kanvasUser,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }

    private function createChannel(string $name): Channels
    {
        $company = $this->kanvasUser->getCurrentCompany();

        return new CreateChannel(
            new ChannelsDto(
                app: $this->kanvasApp,
                company: $company,
                user: $this->kanvasUser,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }
}
