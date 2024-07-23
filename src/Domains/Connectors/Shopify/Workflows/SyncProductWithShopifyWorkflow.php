<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows;

use Baka\Contracts\AppInterface;
use Generator;
use Kanvas\Connectors\Shopify\Workflows\Activities\SyncProductWithShopifyActivity;
use Kanvas\Inventory\Products\Models\Products;
use Workflow\ActivityStub;
use Workflow\Workflow;

class SyncProductWithShopifyWorkflow extends Workflow
{
    public function execute(AppInterface $app, Products $product, array $params): Generator
    {
        return yield ActivityStub::make(SyncProductWithShopifyActivity::class, $app, $product, $params);
    }
}
