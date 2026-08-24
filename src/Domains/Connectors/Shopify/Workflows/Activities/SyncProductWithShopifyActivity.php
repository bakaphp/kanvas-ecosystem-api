<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

use function Sentry\captureException;

#[WorkflowAction(
    name: 'Shopify Sync Product',
    description: 'Pushes the product and its variants to Shopify so the storefront matches Kanvas. Outbound '
        . 'one-way. This is the plain version — the with-integration variant additionally records each '
        . 'attempt in the integration history, which is what you want when a tenant needs an audit '
        . 'trail of syncs.',
    integration: IntegrationsEnum::SHOPIFY,
)]
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
                        'company' => $product->company->getId(),
                        'product' => $product->getId(),
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
