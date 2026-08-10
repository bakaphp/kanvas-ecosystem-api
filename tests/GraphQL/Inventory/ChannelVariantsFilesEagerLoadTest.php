<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Guards the `selectsVariantFiles()` optimization on the channelVariants builder — the fix for the
 * N+1 flagged in Sentry KANVAS-ECOSYSTEM-65Q. The `files` eager-load must:
 *   - fire as a SINGLE batched `filesystem_entities` query when the client selects files
 *     (across many variants — proving the per-variant N+1 is gone), and
 *   - NOT fire at all when the client does not select files (no wasted query).
 *
 * The eager-load runs inside the paginate builder, BEFORE @cacheRedis resolves the field, so it is
 * independent of cache warmth. A fresh channel/variants/files per run keeps the first query cold.
 */
class ChannelVariantsFilesEagerLoadTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testFilesSelectionDrivesASingleBatchedLoadAndNothingWhenUnselected(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->firstOrFail();

        // Dedicated channel so channelVariants returns only this test's variants.
        $channel = new CreateChannel(
            new ChannelsDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'FilesEagerLoadChannel-' . uniqid(),
            ),
            $user
        )->execute();

        // Three published variants, each with its own files — a batched load must collapse these
        // into one query, not three.
        foreach ([2, 1, 3] as $index => $fileCount) {
            $this->publishVariantWithFiles($app, $user, $company, $channel, $warehouse, $index, $fileCount);
        }

        $fileEntityQueries = [];
        DB::listen(function ($query) use (&$fileEntityQueries): void {
            if (str_contains($query->sql, 'filesystem_entities')) {
                $fileEntityQueries[] = $query->sql;
            }
        });

        // Files selected → exactly one batched `entity_id IN (...)` load for all three variants.
        $withFiles = $this->graphQL('
            query ($id: String!) {
                channelVariants(id: $id) {
                    data {
                        id
                        files { data { url } }
                    }
                }
            }
        ', ['id' => $channel->uuid])->assertSuccessful();

        $queriesAfterFilesSelected = count($fileEntityQueries);

        $rows = collect($withFiles->json('data.channelVariants.data'));
        $this->assertCount(3, $rows, 'All three published variants should be returned.');
        $this->assertSame(
            6,
            $rows->sum(fn (array $row): int => count($row['files']['data'])),
            'Every variant must still return its own files through the batched path.'
        );

        $this->assertSame(
            1,
            $queriesAfterFilesSelected,
            'Selecting files must trigger exactly one batched filesystem_entities query, not one per variant.'
        );

        // Files NOT selected → the builder must skip the eager-load entirely.
        $this->graphQL('
            query ($id: String!) {
                channelVariants(id: $id) {
                    data {
                        id
                        sku
                    }
                }
            }
        ', ['id' => $channel->uuid])->assertSuccessful();

        $this->assertSame(
            $queriesAfterFilesSelected,
            count($fileEntityQueries),
            'Not selecting files must not run any filesystem_entities query.'
        );
    }

    private function publishVariantWithFiles(
        Apps $app,
        Users $user,
        mixed $company,
        Channels $channel,
        Warehouses $warehouse,
        int $index,
        int $fileCount
    ): void {
        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'FilesEagerLoadProduct-' . $index . '-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 1,
                'price' => 10.00,
                'sku' => $variant->sku ?? 'FilesEagerLoadSku-' . uniqid(),
                'position' => 1,
                'is_default' => 1,
            ]
        );

        new VariantsChannels([
            'product_variants_warehouse_id' => $variantWarehouse->getId(),
            'channels_id' => $channel->getId(),
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'price' => 10.00,
            'discounted_price' => 9.00,
            'is_published' => true,
        ])->save();

        for ($i = 0; $i < $fileCount; $i++) {
            $variant->addFileFromUrl(
                'https://example.com/eager-' . $index . '-' . $i . '-' . uniqid() . '.png',
                fake()->unique()->uuid() . '.png'
            );
        }
    }
}
