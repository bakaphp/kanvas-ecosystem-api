<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Workflows\Activities\ExportProductToShopifyActivity;
use Kanvas\Inventory\Products\Models\Products;
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

        $exportActivity = new ExportProductToShopifyActivity(
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

        //We need to DELETE the exported product after the test.
        $this->assertArrayHasKey('shopify_response', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('product', $result);
    }
}
