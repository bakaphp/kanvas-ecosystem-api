<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Status\Actions\CreateStatusHistoryAction;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class UpdateToWarehouseAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public VariantsWarehouses $variantsWarehouses,
        public VariantsWarehousesDto $variantsWarehousesDto,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        $oldStatusId = $this->variantsWarehouses->status_id;

        $this->variantsWarehouses->update(
            [
                'quantity' => $this->variantsWarehousesDto->quantity ?? $this->variantsWarehouses->quantity,
                'price' => $this->variantsWarehousesDto->price ?? $this->variantsWarehouses->price,
                'sku' => $this->variantsWarehousesDto->sku ?? $this->variantsWarehouses->sku,
                'position' => $this->variantsWarehousesDto->position ?? $this->variantsWarehouses->position,
                'serial_number' => $this->variantsWarehousesDto->serial_number ?? $this->variantsWarehouses->serial_number,
                'status_id' => $this->variantsWarehousesDto->status_id ?? $this->variantsWarehouses->status_id,
                'is_oversellable' => $this->variantsWarehousesDto->is_oversellable ?? $this->variantsWarehouses->is_oversellable,
                'is_default' => $this->variantsWarehousesDto->is_default ?? $this->variantsWarehouses->is_default,
                'is_best_seller' => $this->variantsWarehousesDto->is_best_seller ?? $this->variantsWarehouses->is_best_seller,
                'is_on_sale' => $this->variantsWarehousesDto->is_on_sale ?? $this->variantsWarehouses->is_on_sale,
                'is_on_promo' => $this->variantsWarehousesDto->is_on_promo ?? $this->variantsWarehouses->is_on_promo,
                'can_pre_order' => $this->variantsWarehousesDto->can_pre_order ?? $this->variantsWarehouses->can_pre_order,
                'is_coming_son' => $this->variantsWarehousesDto->is_coming_son ?? $this->variantsWarehouses->is_coming_son,
                'is_new' => $this->variantsWarehousesDto->is_new ?? $this->variantsWarehouses->is_new
            ]
        );

        if ($this->variantsWarehousesDto->status_id && $oldStatusId !== $this->variantsWarehouses->status_id) {
            (new CreateStatusHistoryAction(
                StatusRepository::getById($this->variantsWarehousesDto->status_id),
                $this->variantsWarehouses
            ))->execute();
        }

        return $this->variantsWarehouses->variant;
    }
}
