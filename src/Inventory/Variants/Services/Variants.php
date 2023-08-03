<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class Variants
{
    /**
     * Create a new product variants.
     *
     * @param Products $product
     * @param array $variants
     * @return array
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
            if (isset($variant['status_id'])) {
                $status = StatusRepository::getById($variant['status_id'], $variantDto->product->company()->get()->first());
                $variant->setStatus($status);
            }

            WarehouseRepository::getById($variantDto->warehouse_id, $variantDto->product->company()->get()->first());
            $variantModel->warehouses()->attach($variantDto->warehouse_id);
            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }
}
