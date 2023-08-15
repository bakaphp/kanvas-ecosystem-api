<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\Actions\UpdateToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants as VariantModel;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class Variants
{
    /**
     * create.
     */
    public function create(mixed $root, array $req): VariantModel
    {
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], auth()->user()->getCurrentCompany())->getId();
        }

        $variantDto = VariantDto::viaRequest($req['input']);
        $action = new CreateVariantsAction($variantDto, auth()->user());
        $variantModel = $action->execute();

        if (isset($req['input']['attributes'])) {
            $variantModel->addAttributes(auth()->user(), $req['input']['attributes']);
        }
        $warehouse = WarehouseRepository::getById($variantDto->warehouse_id, $variantDto->product->company()->get()->first());
        $variantWarehouses = VariantsWarehouses::viaRequest($req['input']['warehouse']);
        (new AddToWarehouse($variantModel, $warehouse, $variantWarehouses))->execute();

        return $variantModel;
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): VariantModel
    {
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], auth()->user()->getCurrentCompany())->getId();
        }  

        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $variant->update($req['input']);

        if (isset($req['input']['attributes'])) {
            $variant->addAttributes(auth()->user(), $req['input']['attributes']);
        }

        return $variant;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        return $variant->softdelete();
    }

    /**
     * addToWarehouse.
     */
    public function addToWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById((int) $req['input']['warehouse_id']);
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], auth()->user()->getCurrentCompany())->getId();
        }
        $variantWarehouses = VariantsWarehouses::viaRequest($req['input']);

        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }

    /**
     * updateVariantInWarehouse.
     */
    public function updateVariantInWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['input']['warehouse_id'], auth()->user()->getCurrentCompany());
        $variantWarehousesDto = VariantsWarehouses::viaRequest($req['input']);
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();

        return (new UpdateToWarehouseAction($variantWarehouses, $variantWarehousesDto))->execute();
    }

    /**
     * removeToWarehouse.
     */
    public function removeToWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById($req['warehouse_id'], auth()->user()->getCurrentCompany());
        $variant->warehouses()->detach($warehouse);

        return $variant;
    }

    /**
     * addAttribute.
     */
    public function addAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById((int) $req['attributes_id']);
        (new AddAttributeAction($variant, $attribute, $req['input']['value']))->execute();

        return $variant;
    }

    /**
     * removeAttribute.
     */
    public function removeAttribute(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $attribute = AttributesRepository::getById((int) $req['attributes_id']);
        $variant->attributes()->detach($attribute);

        return $variant;
    }

    /**
     * addToChannel.
     */
    public function addToChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['warehouses_id']);
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();

        $channel = ChannelRepository::getById((int) $req['channels_id']);
        $variantChannel = VariantChannel::from($req['input']);
        (new AddVariantToChannelAction($variantWarehouses, $channel, $variantChannel))->execute();

        return $variant;
    }

    /**
     * removeChannel.
     */
    public function removeChannel(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $warehouse = WarehouseRepository::getById((int) $req['warehouses_id']);
        $variantWarehouses = ModelsVariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();
        $channel = ChannelRepository::getById((int) $req['channels_id']);
        $variantWarehouses->channels()->where('id', $channel->getId())->detach($channel->id);

        return $variant;
    }
}
