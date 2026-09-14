<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\CreateShopifyDraftOrderAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Shopify Create Draft Order',
    description: 'Creates the order in Shopify as a DRAFT, so a person reviews it before it becomes a real '
        . 'order. Outbound write; nothing is charged and nobody is contacted.',
    integration: IntegrationsEnum::SHOPIFY,
)]
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
                    'order' => $order->getId(),
                    'shopify_draft_order' => $shopifyDraftOrder,
                ];
            },
            company: $company,
        );
    }
}
