<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;

class VariantService
{
    /**
     * Create a new product variants.
     */
    public static function createVariantsFromArray(Products $product, array $variants, UserInterface $user): array
    {
        $variantsData = [];

        foreach ($variants as $variant) {
            $variantDto = VariantsDto::from([
                'product' => $product,
                'products_id' => $product->getId(),
                'warehouse_id' => $variant['warehouse']['id'],
                ...$variant,
            ]);

            $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();
            if (isset($variant['attributes'])) {
                $variantModel->addAttributes($user, $variant['attributes']);
            }

            if (isset($variant['status']['id'])) {
                $status = StatusRepository::getById((int) $variant['status']['id'], $variantDto->product->company()->get()->first());
                $variantModel->setStatus($status);
            }

            $warehouse = WarehouseRepository::getById($variantDto->warehouse_id, $variantDto->product->company()->get()->first());

            if (isset($variant['warehouse']['status'])) {
                $variant['warehouse']['status_id'] = StatusRepository::getById((int) $variant['warehouse']['status']['id'], auth()->user()->getCurrentCompany())->getId();
            }

            $variantWarehouses = VariantsWarehouses::from($variant['warehouse']);
            (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();
            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }
}
