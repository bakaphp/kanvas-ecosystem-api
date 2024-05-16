<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\Actions\UpdateToChannelAction;
use Kanvas\Inventory\Variants\Actions\UpdateToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel as VariantChannelDto;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

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
            $company = $variantDto->product->company()->get()->first();

            if (isset($variant['custom_fields']) && ! empty($variant['custom_fields'])) {
                $variantModel->setAllCustomFields($variant['custom_fields']);
            }

            if (isset($variant['attributes'])) {
                $variantModel->addAttributes($user, $variant['attributes']);
            }
            if (! $variantDto->warehouse_id) {
                $variantDto->warehouse_id = Warehouses::getDefault($company)->getId();
            }
            if (isset($variant['status']['id'])) {
                $status = StatusRepository::getById(
                    (int) $variant['status']['id'],
                    $company
                );
                $variantModel->setStatus($status);
            }

            if (! empty($variantDto->files)) {
                foreach ($variantDto->files as $file) {
                    $variantModel->addFileFromUrl($file['url'], $file['name']);
                }
            }

            $warehouse = WarehouseRepository::getById($variantDto->warehouse_id, $company, $variantDto->product->app);

            if (isset($variant['warehouse']['status'])) {
                $variant['warehouse']['status_id'] = StatusRepository::getById(
                    (int) $variant['warehouse']['status']['id'],
                    $company
                )->getId();
            } else {
                $variant['warehouse']['status_id'] = Status::getDefault($company)->getId();
            }

            if ($variantDto->sku && (! isset($variant['warehouse']['sku']) || ! $variant['warehouse']['sku'])) {
                $variant['warehouse']['sku'] = $variantDto->sku;
            }

            $variantWarehouses = VariantsWarehouses::viaRequest($variant['warehouse'] ?? []);

            (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();
            $variantsData[] = $variantModel;
        }

        return $variantsData;
    }

    /**
     * Create a default variant from the product alone.
     */
    public static function createDefaultVariant(Products $product, UserInterface $user, ?ProductDto $productDto = null): Variants
    {
        $variant = [
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $productDto->sku ?? null,
        ];

        $variantDto = VariantsDto::from([
            'product' => $product,
            'products_id' => $product->getId(),
            ...$variant,
        ]);
        $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();

        $company = $variantDto->product->company()->get()->first();

        $warehouse = Warehouses::getDefault($company);

        if (isset($variant['warehouse']['status'])) {
            $variant['warehouse']['status_id'] = StatusRepository::getById(
                (int) $variant['warehouse']['status']['id'],
                $company
            )->getId();
        } else {
            $variant['warehouse']['status_id'] = Status::getDefault($company)->getId();
        }
        $variantWarehouses = VariantsWarehouses::viaRequest($variant['warehouse'] ?? []);

        (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();

        return $variantModel;
    }

    /**
     * Update data of variant in a warehouse.
     */
    public static function updateWarehouseVariant(Variants $variant, Warehouses $warehouse, array $data): Variants
    {
        if (isset($data['status'])) {
            $data['status_id'] = StatusRepository::getById(
                (int) $data['status']['id'],
                $variant->product->company()->get()->first()
            )->getId();
        }

        $variantWarehousesDto = VariantsWarehouses::viaRequest($data);
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();

        return (
            new UpdateToWarehouseAction(
                $variantWarehouses,
                $variantWarehousesDto
            ))->execute();
    }

    /**
     * Update data of variant in a channel.
     */
    public static function updateVariantChannel(VariantsChannels $variantChannel, array $data): VariantsChannels
    {
        $variantChannelDto = VariantChannelDto::from($data);

        return (
            new UpdateToChannelAction(
                $variantChannel,
                $variantChannelDto
            ))->execute();
    }

    /**
     * Add variants to channels.
     */
    public static function addVariantChannel(
        Variants $variant,
        Warehouses $warehouse,
        Channels $channel,
        VariantChannelDto $variantChannelDto
    ): VariantsChannels {
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
        ->where('warehouses_id', $warehouse->getId())
        ->firstOrFail();

        return (
            new AddVariantToChannelAction(
                $variantWarehouses,
                $channel,
                $variantChannelDto
            ))->execute();
    }

    public static function addToWarehouses(
        Variants $variant, 
        Warehouses $warehouse,
        Companies $company,
        array $warehousesInfo
    ): ModelsVariantsWarehouses {

        if (isset($warehousesInfo['status'])) {
            $status = StatusRepository::getById(
                (int) $warehousesInfo['status']['id'],
                $company
            )->getId();
        } else {
            $status = Status::getDefault($company);
        }

        $warehousesInfo['status_id'] = $status ? $status->getId() : null;
        $variantWarehouses = VariantsWarehouses::viaRequest($warehousesInfo ?? []);

        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }
}
