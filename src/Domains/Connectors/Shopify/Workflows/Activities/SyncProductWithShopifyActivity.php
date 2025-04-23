<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

use function Sentry\captureException;

class SyncProductWithShopifyActivity extends KanvasActivity
{
    //public $queue = ConfigEnum::ACTIVITY_QUEUE->value;

    public function execute(Products $product, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $product->company;

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::SHOPIFY,
            integrationOperation: function () use ($product) {
                try {
                    $syncProductWithShopify = new SyncProductWithShopifyAction($product);
                    $response = $syncProductWithShopify->execute();

                    return [
                        'company'          => $product->company->getId(),
                        'product'          => $product->getId(),
                        'shopify_response' => $response,
                    ];
                } catch (Throwable $e) {
                    captureException($e);

                    return [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ];
                }
            },
            company: $company,
        );
    }
}
