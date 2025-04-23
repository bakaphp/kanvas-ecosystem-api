<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Exception;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Actions\ImagesGraphql;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

class SaveCustomFieldDataAction
{
    public function __construct(
        protected Warehouses $warehouse,
        protected Products $products,
        protected Regions $region,
        protected ?string $originalName = null,
        protected array $images = [],
        protected ?CompaniesBranches $branch = null
    ) {
    }

    public function execute(): void
    {
        if (!$this->images) {
            if (!$this->branch) {
                throw new Exception('Branch is required');
            }
            $images = (new ImagesGraphql(
                $this->products->app,
                $this->branch,
                $this->warehouse,
                $this->products
            ))->execute();
        }
        $shopifyData = [
            'shopify_id'       => $this->products->getShopifyId($this->region),
            'image'            => $this->products->getFiles()[0]->url,
            'price'            => $this->products->variants()->first()->getPrice($this->warehouse),
            'discounted_price' => 0,
            'title'            => $this->products->name,
            'images'           => $this->images ?? $images,
            'en_title'         => $this->originalName,
        ];
        $this->products->set('shopify_data', $shopifyData);
    }
}
