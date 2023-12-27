<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Support;

use Baka\Contracts\AppInterface;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehousePriceHistory;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;

class CreateSystemModule
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    public function run(): void
    {
        $createSystemModule = new CreateInCurrentAppAction($this->app);

        $createSystemModule->execute(Products::class);
        $createSystemModule->execute(ProductsTypes::class);
        $createSystemModule->execute(Attributes::class);
        $createSystemModule->execute(Categories::class);
        $createSystemModule->execute(Regions::class);
        $createSystemModule->execute(Channels::class);
        $createSystemModule->execute(Variants::class);
        $createSystemModule->execute(VariantsAttributes::class);
        $createSystemModule->execute(VariantsChannels::class);
        $createSystemModule->execute(VariantsWarehouses::class);
        $createSystemModule->execute(VariantsWarehousePriceHistory::class);
        $createSystemModule->execute(Warehouses::class);
    }
}
