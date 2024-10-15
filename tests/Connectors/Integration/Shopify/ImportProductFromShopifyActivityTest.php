<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Services\ShopifyProductService;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\TestCase;

final class ImportProductFromShopifyActivityTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testImportProductWorkflow(): void
    {
        // We the the first product to ensure a valid warehouse for the test.
        $product = Products::first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();
        $app = app(Apps::class);

        $integrationCompany = $this->setupShopifyIntegration($product, $warehouse->region);

        $warehouses = Warehouses::where('regions_id',$integrationCompany->region_id)
        ->fromCompany($integrationCompany->company)
        ->fromApp($app)
        ->get();

        $productData = $this->getProductTestData();
        $shopifyProductService = new ShopifyProductService(
            app: $app,
            company: $integrationCompany->company,
            region: $integrationCompany->region,
            productId: $productData['id'],
        );

        $mappedProduct = $shopifyProductService->mapProductForImport($productData);
        foreach ($mappedProduct['variants'] as $key => $variant) {
            $mappedProduct['variants'][$key]['warehouses'] = $warehouses->toArray();
        }

        $product = (new ProductImporterAction(
            ProductImporter::from($mappedProduct),
            $integrationCompany->company,
            $product->user,
            $integrationCompany->region,
            $app
        ))->execute();

        $this->assertEquals(
            $product->name,
            $productData['title']
        );

        $this->assertEquals(
            $product->getShopifyId($warehouse->regions),
            $productData['id']
        );
    }

    public function getProductTestData(): array
    {
        return json_decode('{
        "admin_graphql_api_id": "gid://shopify/Product/9723612463420",
        "body_html": null,
        "created_at": "2024-10-13T10:35:20-04:00",
        "handle": "producto-for-the-sync-import",
        "id": 9723612463420,
        "product_type": null,
        "published_at": "2024-10-13T10:35:20-04:00",
        "template_suffix": null,
        "title": "Producto for the sync import 2",
        "updated_at": "2024-10-13T10:35:21-04:00",
        "vendor": "devkanvas",
        "status": "active",
        "published_scope": "global",
        "tags": null,
        "variants": [
        {
            "admin_graphql_api_id": "gid://shopify/ProductVariant/50202925105468",
            "barcode": null,
            "compare_at_price": null,
            "created_at": "2024-10-13T10:35:21-04:00",
            "id": 50202925105468,
            "inventory_policy": "deny",
            "position": 2,
            "price": "0.00",
            "product_id": 9723612463420,
            "sku": null,
            "taxable": true,
            "title": "Default Title",
            "updated_at": "2024-10-13T10:35:21-04:00",
            "option1": "Default Title",
            "option2": null,
            "option3": null,
            "image_id": null,
            "inventory_item_id": 52137167716668,
            "inventory_quantity": 4,
            "old_inventory_quantity": 4
        }],
        "options": [
        {
            "name": "Title",
            "id": 12157131063612,
            "product_id": 9723612463420,
            "position": 1,
            "values": ["Default Title"]
        }],
        "images": [],
        "image": null,
        "media": [],
        "variant_gids": [
        {
            "admin_graphql_api_id": "gid://shopify/ProductVariant/50202925105468",
            "updated_at": "2024-10-13T14:35:21.000Z"
        }]
    }', true); // true to return as associative array
    }
}
