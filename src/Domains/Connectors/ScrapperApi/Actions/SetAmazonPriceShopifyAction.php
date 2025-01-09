<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Kanvas\Inventory\Products\Models\Products;

class SetAmazonPriceShopifyAction
{
    public function __construct(public Products $product)
    {
    }

    public function execute(): array
    {
    }
}
