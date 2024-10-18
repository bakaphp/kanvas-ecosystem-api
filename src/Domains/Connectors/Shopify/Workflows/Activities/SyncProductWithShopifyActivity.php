<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Inventory\Products\Models\Products;
use Workflow\Activity;

class SyncProductWithShopifyActivity extends Activity
{
    public $tries = 5;

    public function execute(Apps $app, Products $product, array $params): array
    {
        $syncProductWithShopify = new SyncProductWithShopifyAction($product);
        $response = $syncProductWithShopify->execute();

        return [
            'company' => $product->company->getId(),
            'product' => $product->getId(),
            'shopify_response' => $response,
        ];
    }
}
