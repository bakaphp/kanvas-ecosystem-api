<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Status\Actions;

use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Models\VariantWarehouseStatusHistory;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class CreateStatusHistoryAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected Status $status,
        protected VariantsWarehouses $variantsWarehouses
    ) {
    }

    /**
     * execute.
     *
     * @return VariantWarehouseStatusHistory
     */
    public function execute(): VariantWarehouseStatusHistory
    {
        return VariantWarehouseStatusHistory::firstOrCreate([
            'status_id' => $this->status->getId(),
            'products_variants_warehouse_id' => $this->variantsWarehouses->getId(),
            'from_date' => date('Y-m-d H:i:s'),
        ]);
    }
}
