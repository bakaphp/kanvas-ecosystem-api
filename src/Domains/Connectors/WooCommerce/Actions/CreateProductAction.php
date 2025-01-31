<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\DataTransferObject\WooCommerceImportProduct;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Exception;
use Kanvas\Inventory\Products\Models\Products;

class CreateProductAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected Regions $region,
        public object $product
    ) {
    }

    public function execute(): Products
    {
        $variants = [];
        foreach ($this->product->variations as $variant) {
            $wooCommerce = new WooCommerce($this->app);
            $variants[] = $wooCommerce->client->get('products/' . $this->product->id . '/variations/' . $variant);
        }
        $variants = empty($variants) ? [$this->product] : $variants;
        $this->product->variations = $variants;
        $productDto = WooCommerceImportProduct::fromWooCommerce(
            $this->product
        );
        return (new ProductImporterAction(
            $productDto,
            $this->company,
            $this->user,
            $this->region,
            $this->app,
        ))->execute();
    }
}
