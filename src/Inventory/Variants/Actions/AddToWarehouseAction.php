<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;

class AddToWarehouseAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public Variants $variants,
        public Warehouses $warehouses,
        public VariantsWarehouses $variantsWarehouses,
    ) {
    }

    /**
     * execute
     *
     * @return Variants
     */
    public function execute(): Variants
    {
        if ($this->variants->warehouses()->find($this->warehouses->id)) {
            $this->variants->warehouses()->syncWithoutDetaching([$this->warehouses->id => $this->variantsWarehouses->toArray()]);
        } else {
            $this->variants->warehouses()->attach($this->warehouses->id, $this->variantsWarehouses->toArray());
        }
        return $this->variants;
    }
}
