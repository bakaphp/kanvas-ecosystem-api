<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants;
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
        public VariantsWarehouses $variantsWarehouses,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        if ($this->variants->warehouses()->find($this->warehouses->getId())) {
            $this->variants->warehouses()->syncWithoutDetaching([$this->warehouses->getId() => $this->variantsWarehouses->toArray()]);
        } else {
            $this->variants->warehouses()->attach($this->warehouses->getId(), $this->variantsWarehouses->toArray());
        }

        return $this->variants;
    }
}
