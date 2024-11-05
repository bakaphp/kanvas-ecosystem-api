<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Inventory\Products\Models\Products;
use Laravel\Octane\Facades\Octane;

use function Sentry\captureException;

use Throwable;
use Workflow\Activity;

class SyncProductWithShopifyActivity extends Activity
{
    public $tries = 3;
    // public $queue = ConfigEnum::ACTIVITY_QUEUE->value;

    public function execute(Products $product, Apps $app, array $params): array
    {
        try {
            $syncProductWithShopify = new SyncProductWithShopifyAction($product);
            Octane::concurrently([
                fn () => $syncProductWithShopify->execute(),
            ]);

            return [
                'company' => $product->company->getId(),
                'product' => $product->getId(),
                // 'shopify_response' => $response,
            ];
        } catch (Throwable $e) {
            captureException($e);

            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
