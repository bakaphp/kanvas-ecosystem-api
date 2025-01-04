<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Attributes\Enums\ConfigEnum as AttributeConfigEnum;
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
use Kanvas\Inventory\Variants\Actions\UpdateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel as VariantChannelDto;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
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
            /**
             * @todo file upload mapper set type
             */
            if (empty($variant['files'])) {
                $variant['files'] = [];
            }

            $variantDto = VariantsDto::from([
                'product' => $product,
                'products_id' => $product->getId(),
                ...$variant,
            ]);

            $existVariantUpdate = Variants::fromCompany($product->company)->fromApp($product->app)->where('sku', $variantDto->sku);

            if (! $existVariantUpdate->exists()) {
                $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();
            } else {
                $variantModel = (new UpdateVariantsAction($existVariantUpdate->first(), $variantDto, $user))->execute();
            }

            $company = $variantDto->product->company;

            if (isset($variant['custom_fields']) && ! empty($variant['custom_fields'])) {
                $variantModel->setAllCustomFields($variant['custom_fields']);
            }
            $attributes = $product->app->get(AttributeConfigEnum::DEFAULT_VARIANT_ATTRIBUTE->value);
            $attributes = $attributes && is_array($attributes) ? $attributes : [];
            if (isset($variant['attributes'])) {
                $attributes = array_merge($attributes, $variant['attributes']); // to do: refactor for default attributes variant
                $variantModel->addAttributes($user, $attributes);
            }

            if (isset($variant['status']['id'])) {
                $status = StatusRepository::getById(
                    (int) $variant['status']['id'],
                    $company
                );
                $variantModel->setStatus($status);
            }

            if (! empty($variantDto->files)) {
                $variantModel->overWriteFiles($variantDto->files);
            }

            if (isset($variant['warehouses'])) {
                foreach ($variant['warehouses'] as $warehouseData) {
                    $warehouse = WarehouseRepository::getById((int) $warehouseData['id'], $company);
                    WarehouseService::addToWarehouses(
                        $variantModel,
                        $warehouse,
                        $company,
                        $warehouseData
                    );
                }
            } else {
                $warehouse = Warehouses::getDefault($company);
                WarehouseService::addToWarehouses(
                    $variantModel,
                    $warehouse,
                    $company,
                    []
                );
            }

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
            'sku' => $productDto->sku ?? Str::slug($product->name),
        ];

        $variantDto = VariantsDto::from([
            'product' => $product,
            'products_id' => $product->getId(),
            ...$variant,
        ]);
        $variantModel = (new CreateVariantsAction($variantDto, $user))->execute();

        $company = $variantDto->product->company;

        $warehouse = Warehouses::getDefault($company);

        if (isset($variant['warehouse']['status'])) {
            $variant['warehouse']['status_id'] = StatusRepository::getById(
                (int) $variant['warehouse']['status']['id'],
                $company
            )->getId();
        } else {
            $variant['warehouse']['status_id'] = Status::getDefault($company)->getId();
        }

        if (! empty($productDto->warehouses) && isset($productDto->warehouses[0]['quantity']) && isset($productDto->warehouses[0]['price'])) {
            $variant['warehouse']['quantity'] = $productDto->warehouses[0]['quantity'];
            $variant['warehouse']['price'] = $productDto->warehouses[0]['price'];
        }

        $variantWarehouses = VariantsWarehouses::viaRequest($variantModel, $warehouse, $variant['warehouse'] ?? []);

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
                $variant->product->company
            )->getId();
        } else {
            $data['status_id'] = Status::getDefault($variant->product->company)->getId();
        }

        $variantWarehousesDto = VariantsWarehouses::viaRequest($variant, $warehouse, $data);

        return (
            new UpdateToWarehouseAction(
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
}
