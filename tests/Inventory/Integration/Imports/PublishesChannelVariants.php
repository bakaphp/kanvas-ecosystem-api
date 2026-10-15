<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Enums\ProductImportRunStatusEnum;
use Kanvas\Inventory\Importer\Models\ProductImportRun;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\GraphQL\Inventory\Traits\InventoryCases;

trait PublishesChannelVariants
{
    use InventoryCases;

    /**
     * A fresh channel, with every open run for the company closed first: runs are per company,
     * and one left open by another test would otherwise be joined, with its older start time.
     *
     * @return array{0: Channels, 1: list<Variants>}
     */
    protected function publishVariantsInANewChannel(int $count): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        ProductImportRun::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('status', ProductImportRunStatusEnum::OPEN->value)
            ->update(['status' => ProductImportRunStatusEnum::ABANDONED->value]);

        $this->setupInventory($app, $company, $user);

        $channel = Channels::create([
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'name' => 'Import Run Channel ' . fake()->unique()->uuid(),
            'is_default' => 0,
            'is_published' => 1,
            'is_deleted' => 0,
        ]);

        $variants = $this->publishVariantsIn($channel, $count);

        // The sweep only considers variants published before the run started; same-second
        // timestamps would make every fixture look like it was created during the run.
        $this->travel(1)->minutes();

        return [$channel, $variants];
    }

    /**
     * @return list<Variants>
     */
    protected function publishVariantsIn(Channels $channel, int $count): array
    {
        $company = auth()->user()->getCurrentCompany();
        $warehouse = Warehouses::fromApp(app(Apps::class))->fromCompany($company)->first();

        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $productId = $this->createProduct()->json('data.createProduct.id');
            $variantId = $this->createVariant(
                (string) $productId,
                ['id' => $warehouse->getId(), 'price' => 10.00, 'quantity' => 1, 'position' => 1]
            )->json('data.createVariant.id');
            $this->addVariantToChannel((string) $variantId, (string) $channel->getId(), ['id' => $warehouse->getId()])
                ->assertJsonMissingPath('errors.0');
            $variants[] = Variants::getById((int) $variantId);
        }

        return $variants;
    }

    /**
     * Gives each variant the SKU shape a feed uses (a VIN, a SuperCarros ad id) so the feed can match it.
     *
     * @param list<Variants> $variants
     * @param callable(): string $makeSku
     */
    protected function resku(array $variants, callable $makeSku): void
    {
        foreach ($variants as $variant) {
            Variants::where('id', $variant->getId())->update(['sku' => $makeSku()]);
            $variant->refresh();
        }
    }

    protected function publishedIn(Channels $channel, Variants $variant): int
    {
        return (int) VariantsChannels::where('channels_id', $channel->getId())
            ->where('products_variants_id', $variant->getId())
            ->value('is_published');
    }
}
