<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use App\GraphQL\Inventory\Mutations\Warehouses\Warehouse;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class UpdateToWarehouseAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public VariantsWarehousesDto $variantsWarehousesDto,
    ) {
    }

    /**
     * execute.
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function execute(): Variants
    {
        $search = [
            'products_variants_id' => $this->variantsWarehousesDto->variant->getId(),
            'warehouses_id' => $this->variantsWarehousesDto->warehouse->getId()
        ];

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            $search,
            [
                'quantity' => $this->variantsWarehousesDto->quantity,
                'price' => $this->variantsWarehousesDto->price,
                'sku' => $this->variantsWarehousesDto->sku,
                'position' => $this->variantsWarehousesDto->position,
                'serial_number' => $this->variantsWarehousesDto->serial_number,
                'status_id' => $this->variantsWarehousesDto->status_id,
                'is_oversellable' => $this->variantsWarehousesDto->is_oversellable,
                'is_default' => $this->variantsWarehousesDto->is_default ?? $this->variantsWarehouses->is_default,
                'is_best_seller' => $this->variantsWarehousesDto->is_best_seller,
                'is_on_sale' => $this->variantsWarehousesDto->is_on_sale,
                'is_on_promo' => $this->variantsWarehousesDto->is_on_promo,
                'can_pre_order' => $this->variantsWarehousesDto->can_pre_order,
                'is_coming_son' => $this->variantsWarehousesDto->is_coming_son,
                'is_new' => $this->variantsWarehousesDto->is_new,
            ]
        );

        return $variantWarehouse->variant;
    }
}
