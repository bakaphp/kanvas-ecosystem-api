<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\CreateShopifyDraftOrderAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateShopifyDraftOrderActivity extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $order->company;

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::SHOPIFY,
            integrationOperation: function () use ($order) {
                $createDraftOrder = new CreateShopifyDraftOrderAction($order);
                $shopifyDraftOrder = $createDraftOrder->execute();

                return [
                    'order'               => $order->getId(),
                    'shopify_draft_order' => $shopifyDraftOrder,
                ];
            },
            company: $company,
        );
    }
}
