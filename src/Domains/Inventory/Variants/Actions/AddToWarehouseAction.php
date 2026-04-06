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

        $updateData = [
            'sku' => $this->variantsWarehousesDto->sku ?? $this->variants->sku,
            'quantity' => $this->variantsWarehousesDto->quantity,
            'price' => $this->variantsWarehousesDto->price,
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

        if ($this->variantsWarehousesDto->max_capacity !== null) {
            $updateData['max_capacity'] = $this->variantsWarehousesDto->max_capacity;
        }

        if ($this->variantsWarehousesDto->latitude !== null) {
            $updateData['latitude'] = $this->variantsWarehousesDto->latitude;
        }

        if ($this->variantsWarehousesDto->longitude !== null) {
            $updateData['longitude'] = $this->variantsWarehousesDto->longitude;
        }

        $variantsWarehouses = VariantsWarehouses::updateOrCreate($search, $updateData);

        return $variantsWarehouses;
    }
}
