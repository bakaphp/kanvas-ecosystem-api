<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Kanvas\Inventory\Warehouses\Services\WarehouseService;

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
                ...$variant,
            ]);

            $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();

            if (isset($variant['attributes'])) {
                $variantModel->addAttributes($user, $variant['attributes']);
            }
            if (!$variantDto->warehouse_id) {
                $variantDto->warehouse_id = WarehouseService::getDefault()->getId();
            }
            if (isset($variant['status']['id'])) {
                $status = StatusRepository::getById((int) $variant['status']['id'], $variantDto->product->company()->get()->first());
                $variantModel->setStatus($status);
            }
            if (! empty($variantDto->files)) {
                foreach ($variantDto->files as $file) {
                    $variantModel->addFileFromUrl($file['url'], $file['name']);
                }
            }
            $warehouse = WarehouseRepository::getById($variantDto->warehouse_id, $variantDto->product->company()->get()->first());

            if (isset($variant['warehouse']['status'])) {
                $variant['warehouse']['status_id'] = StatusRepository::getById((int) $variant['warehouse']['status']['id'], $variantDto->product->company()->get()->first())->getId();
            }
            if (isset($variant['warehouse'])) {
                $variantWarehouses = VariantsWarehouses::viaRequest($variant['warehouse']);
            } else {
                $variantWarehouses = VariantsWarehouses::viaRequest([]);
            }
            (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();
            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }
}
