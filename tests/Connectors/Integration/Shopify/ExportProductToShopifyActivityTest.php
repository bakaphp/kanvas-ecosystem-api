<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyWithIntegrationActivity;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Services\EntityIntegrationHistoryService;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\TestCase;

final class ExportProductToShopifyActivityTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testExportProductWorkflow(): void
    {
        $product = Products::first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();

        $this->setupShopifyIntegration($product, $warehouse->region);

        $exportActivity = new SyncProductWithShopifyWithIntegrationActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $app = app(Apps::class);

        $result = $exportActivity->execute(
            product: $product,
            app: $app,
            params: []
        );

        //@todo We need to DELETE the exported product after the test.
        $this->assertArrayHasKey('shopify_response', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('product', $result);
    }

    public function testIntegrationHistory(): void
    {
        $integration = Integrations::first();
        $product = Products::first();

        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();

        $this->setupShopifyIntegration($product, $warehouse->region);

        $exportActivity = new SyncProductWithShopifyWithIntegrationActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $app = app(Apps::class);

        $result = $exportActivity->execute(
            product: $product,
            app: $app,
            params: []
        );

        //@todo We need to DELETE the exported product after the test.
        $this->assertArrayHasKey('shopify_response', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('product', $result);

        $histories = (new EntityIntegrationHistoryService(
            app: $app,
            company: $product->company
        ))->getByIntegration($integration);

        $this->assertNotEmpty($histories);
        $this->assertInstanceOf(EntityIntegrationHistory::class, $histories[0]);
    }
}
