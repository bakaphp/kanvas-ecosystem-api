<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class AddToWarehouseAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public Variants $variants,
        public Warehouses $warehouses,
        public VariantsWarehousesDto $variantsWarehousesDto,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function execute(): VariantsWarehouses
    {
        $search = [
            'products_variants_id' => $this->variants->getId(),
            'warehouses_id' => $this->warehouses->getId(),
        ];

        $existing = VariantsWarehouses::where($search)->first();

        $updateData = [
            'sku' => $this->variantsWarehousesDto->sku ?? $this->variants->sku,
            'position' => $this->variantsWarehousesDto->position,
            'serial_number' => $this->variantsWarehousesDto->serial_number,
            'status_id' => $this->variantsWarehousesDto->status_id,
            'is_oversellable' => $this->variantsWarehousesDto->is_oversellable,
            'is_default' => $this->variantsWarehousesDto->is_default,
            'is_best_seller' => $this->variantsWarehousesDto->is_best_seller,
            'is_on_sale' => $this->variantsWarehousesDto->is_on_sale,
            'is_on_promo' => $this->variantsWarehousesDto->is_on_promo,
            'can_pre_order' => $this->variantsWarehousesDto->can_pre_order,
            'is_coming_son' => $this->variantsWarehousesDto->is_coming_son,
            'is_new' => $this->variantsWarehousesDto->is_new,
            'config' => $this->variantsWarehousesDto->config,
        ];

        // For new records, quantity and price must be provided; default to 0.
        // For existing records, only update if explicitly provided.
        if ($existing) {
            if ($this->variantsWarehousesDto->quantity !== null) {
                $updateData['quantity'] = $this->variantsWarehousesDto->quantity;
            }
            if ($this->variantsWarehousesDto->price !== null) {
                $updateData['price'] = $this->variantsWarehousesDto->price;
            }
        } else {
            $updateData['quantity'] = $this->variantsWarehousesDto->quantity ?? 0;
            $updateData['price'] = $this->variantsWarehousesDto->price ?? 0.00;
        }

        if ($this->variantsWarehousesDto->max_capacity !== null) {
            $updateData['max_capacity'] = $this->variantsWarehousesDto->max_capacity;
        }

        $variantsWarehouses = VariantsWarehouses::updateOrCreate($search, $updateData);

        return $variantsWarehouses;
    }
}
