<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannel;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
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
        $variantDto = VariantDto::viaRequest($req['input']);
        $action = new CreateVariantsAction($variantDto, auth()->user());
        $variantModel = $action->execute();

        WarehouseRepository::getById($variantDto->warehouse_id, $variantDto->product->company()->get()->first());
        $variantModel->warehouses()->attach($variantDto->warehouse_id);

        return $variantModel;
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $variant->update($req['input']);

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

        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
        $variantWarehouses = VariantsWarehouses::from($req['input']);

        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }

    /**
     * removeToWarehouse.
     */
    public function removeToWarehouse(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        $warehouse = WarehouseRepository::getById($req['warehouse_id']);
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
        (new AddVariantToChannel($variantWarehouses, $channel, $variantChannel))->execute();

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
