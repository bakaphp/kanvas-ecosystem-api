<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Actions;

use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\VariantsWarehousesPriceHistory;

class CreatePriceHistoryAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected float $price
    ) {
    }

    /**
     * execute.
     *
     * @return VariantsWarehousesPriceHistory
     */
    public function execute(): VariantsWarehousesPriceHistory
    {
        return VariantsWarehousesPriceHistory::firstOrCreate(
            [
                'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
                'price'                         => $this->price,
            ],
            [
                'from_date' => date('Y-m-d H:i:s'),
            ]
        );
    }
}
