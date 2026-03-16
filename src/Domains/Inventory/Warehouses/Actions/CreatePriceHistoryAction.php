<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\VariantsWarehousesPriceHistory;

class CreatePriceHistoryAction
{
    public function __construct(
        protected VariantsWarehouses $variantsWarehouses,
        protected float $price,
        protected ?UserInterface $user = null,
    ) {
    }

    public function execute(): VariantsWarehousesPriceHistory
    {
        return VariantsWarehousesPriceHistory::firstOrCreate(
            [
                'product_variants_warehouse_id' => $this->variantsWarehouses->getId(),
                'price' => $this->price,
            ],
            [
                'from_date' => date('Y-m-d H:i:s'),
                'users_id' => $this->user?->getId() ?? 0,
            ]
        );
    }
}
