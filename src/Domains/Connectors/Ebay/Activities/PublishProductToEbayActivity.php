<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ebay\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Ebay\Actions\PublishEbayListingAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(name: 'eBay Publish Product Listing', integration: IntegrationsEnum::EBAY)]
class PublishProductToEbayActivity extends KanvasActivity
{
    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    public function execute(Products|Variants $product, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::EBAY,
            additionalParams: $params,
            integrationOperation: fn () => $this->publish($product, $params),
            company: $product->company,
        );
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    protected function publish(Products|Variants $product, array $params): array
    {
        return new PublishEbayListingAction($product, $params)->execute();
    }
}
