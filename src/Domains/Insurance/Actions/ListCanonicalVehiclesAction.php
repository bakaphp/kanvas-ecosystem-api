<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Inventory\Products\Models\Products;

class ListCanonicalVehiclesAction
{
    public function __construct(
        protected AppInterface $app,
    ) {
    }

    public function execute(): array
    {
        $vehicles = [];

        $query = Products::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->whereHas(
                'productsType',
                fn ($type) => $type->where('slug', InsuranceProductTypeEnum::VEHICLE->value)
            );

        foreach ($query->cursor() as $product) {
            $vehicle = CanonicalVehicle::fromProduct($product);

            if ($vehicle->isIdentifiable()) {
                $vehicles[$vehicle->brandKey() . '|' . $vehicle->modelKey()] ??= $vehicle;
            }
        }

        return array_values($vehicles);
    }
}
